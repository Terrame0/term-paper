{
  pkgs,
  my-lib,
  server-config,
  lib,
  ...
}:
with server-config; let
  pool-config = pkgs.writeText "pool.conf" (
    ''
      [main-pool]
      listen = ${fpm-socket}
      listen.mode = 0700
      pm = dynamic
      pm.max_children = 5
      pm.start_servers = 2
      pm.min_spare_servers = 1
      pm.max_spare_servers = 3
    ''
    + (
      lib.concatStringsSep "\n"
      (
        lib.mapAttrsToList
        (name: value: "env[${name}] = ${value}")
        server-config
      )
    )
  );

  main-config = pkgs.writeText "main.conf" ''
    [global]
    error_log = ${fpm-log}
    pid = ${fpm-pid}
    include = ${pool-config}
  '';
in
  my-lib.mk-script-union {
    pname = "php-mgr";
    deps = [pkgs.php];
    scripts = my-lib.mk-worker-scripts {
      name = "php-fpm";
      dir = php-dir;
      start-command = ''
        php-fpm -y ${main-config}
      '';
      pid-file = fpm-pid;
    };
  }
