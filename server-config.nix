rec {
  # -- project state directory
  state-dir = "/tmp/term-paper";

  # -- postgres parameters
  pg-dir = "${state-dir}/postgres";
  pg-data-dir = "${pg-dir}/data";
  pg-socket-dir = "${pg-dir}/sockets";
  pg-log = "${pg-dir}/pg.log";
  pg-pid = "${pg-dir}/pg.pid";
  db-user = "main-user";
  db-name = "test";

  # -- nginx parameters
  ngx-server-name = "term-paper";
  ngx-port = "8008";
  ngx-dir = "${state-dir}/nginx";
  ngx-log = "${ngx-dir}/ngx.log";
  ngx-pid = "${ngx-dir}/ngx.pid";
  ngx-hist = "${ngx-dir}/ngx.hist";

  # -- php parameters
  php-dir = "${state-dir}/php";
  fpm-socket = "${php-dir}/fpm.sock";
  fpm-log = "${php-dir}/fpm.log";
  fpm-pid = "${php-dir}/fpm.pid";
}
