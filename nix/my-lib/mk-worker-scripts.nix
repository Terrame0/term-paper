{
  my-lib,
  pkgs,
  ...
}: let
in {
  mk-worker-scripts = {
    name,
    dir,
    pid-file,
    init-command ? ":",
    start-command,
    stop-command ? ":",
  }: let
    stop-script = pkgs.writeShellScript "${name}-stop-script" ''
      ${stop-command}
      systemctl --user reset-failed ${name} 2>/dev/null || true
    '';
    start-script = pkgs.writeShellScript "${name}-start-script" ''
      systemd-run --user \
        --unit=${name} \
        --slice=background.slice \
        --property=Type=forking \
        --property=PIDFile=${pid-file} \
        --property=ExecStop="${stop-script}" \
        ${start-command}
    '';
    init-script = pkgs.writeShellScript "${name}-init-script" init-command;
  in {
    "${name}-start" = ''
      if ${my-lib.check-unit name}; then
        echo "${name} is already running with pid ${my-lib.cat pid-file}!"
      else
        if [ ! -d "${dir}" ]; then
          if mkdir -p "${dir}" && ${init-script}; then
            echo "initialized ${name} unit directory: '${dir}'"
          fi
        fi
        ${start-script}
      fi
    '';
    "${name}-stop" = ''
      if ${my-lib.check-unit name}; then
        if systemctl --user stop ${name}; then
          echo "stopped unit '${name}'"
        fi
      else
        echo "'${name}' unit isn't running!"
      fi
    '';
  };
}
