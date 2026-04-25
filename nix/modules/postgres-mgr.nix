{
  pkgs,
  my-lib,
  server-config,
  ...
}:
with server-config;
  my-lib.mk-script-union {
    pname = "postgres-mgr";
    deps = [
      pkgs.postgresql
    ];
    scripts = my-lib.mk-worker-scripts {
      name = "postgres";
      init-command = ''
        initdb -D "${pg-data-dir}"
        mkdir -p "${pg-socket-dir}"
        {
          echo "listen_addresses = ' '";
          echo "unix_socket_directories = '${pg-socket-dir}'";
          echo "unix_socket_permissions = 0700";
          echo "external_pid_file = '${pg-pid}'";
        } >> "${pg-data-dir}/postgresql.conf"
      '';
      dir = pg-dir;
      run-command = ''
        pg_ctl -D "${pg-data-dir}" -l "${pg-log}" start
      '';
      pid-file = pg-pid;
    };
  }
