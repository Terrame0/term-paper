{
  pkgs,
  my-lib,
  server-config,
  flake-root,
  ...
}:
with server-config; let
  config = let
    server-root =
      (import ../../php/project.nix)
      {
        inherit pkgs;
        inherit flake-root;
      };
  in
    pkgs.writeText "nginx.conf" ''
      error_log ${ngx-log};
      pid ${ngx-pid};
      events {}
      http {
        access_log ${ngx-hist};
        server {
          location / {
            try_files $uri $uri/ /main.php?$args;
          }
          location ~ \.php$ {
            include ${pkgs.nginx}/conf/fastcgi.conf;
            fastcgi_pass unix:${fpm-socket};
          }
          listen ${ngx-port};
          server_name ${ngx-server-name};
          root ${server-root};
          autoindex on;
        }
      }
    '';
  #index main.php;
in
  my-lib.mk-script-union {
    pname = "nginx-mgr";
    deps = [pkgs.nginx];
    scripts = my-lib.mk-worker-scripts {
      name = "nginx";
      dir = ngx-dir;
      run-command = ''
        nginx -p "${ngx-dir}" -c "${config}"
      '';
      pid-file = ngx-pid;
    };
  }
