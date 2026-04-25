{
  pkgs,
  my-lib,
  lib,
  server-config,
  flake-root,
  ...
}:
with server-config; let
  pool-config = pkgs.writeText "pool.conf" ''
    [main-pool]
    listen = ${fpm-socket}
    listen.mode = 0700
    pm = dynamic
    pm.max_children = 5
    pm.start_servers = 2
    pm.min_spare_servers = 1
    pm.max_spare_servers = 3
  '';
  main-config = pkgs.writeText "main.conf" ''
    [global]
    error_log = ${fpm-log}
    pid = ${fpm-pid}
    include = ${pool-config}
  '';
  # phpoffice = pkgs.php.buildComposerProject2 (finalAttrs: rec {
  #   pname = "phpoffice";
  #   version = "5.7.0";
  #   src = pkgs.fetchgit {
  #     url = "https://github.com/PHPOffice/PhpSpreadsheet.git";
  #     rev = "${version}";
  #     hash = "sha256-/FZ/U3J5wpZLzspHePwzKjZJaxfIfahhDdy5YDS7dbQ=";
  #   };
  #   vendorHash = "sha256-ViSTrQcyD6Y56tKF/leCbJXesV4PCwTJExUFqPE+CkE=";
  # });
in
  my-lib.mk-script-union {
    pname = "php-mgr";
    deps = [pkgs.php];
    scripts = my-lib.mk-worker-scripts {
      name = "php-fpm";
      dir = php-dir;
      run-command = ''
        php-fpm -y ${main-config}
      '';
      pid-file = fpm-pid;
    };
  }
