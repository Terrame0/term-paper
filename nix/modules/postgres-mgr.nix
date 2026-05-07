{
  pkgs,
  my-lib,
  server-config,
  pgschema,
  ...
}:
with server-config;
  my-lib.mk-script-union {
    pname = "postgres-mgr";
    deps = [
      pkgs.postgresql
    ];
    scripts = my-lib.mk-worker-scripts rec {
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
        # createuser -h "${pg-socket-dir}" ${db-user}
        # createdb -h "${pg-socket-dir}" -O ${db-user} ${db-name}
        # psql -h "${pg-socket-dir}" -U ${db-user} -d ${db-name} -f ${pgschema}
      '';
      dir = pg-dir;
      start-command = ''
        pg_ctl -D "${pg-data-dir}" -l "${pg-log}" start
      '';
      stop-command = ''
        pg_ctl -D "${pg-data-dir}" stop
      '';
      pid-file = pg-pid;
    };
  }
