{my-lib, ...}: let
in {
  mk-worker-scripts = {
    name,
    dir ? "",
    pid-file,
    init-command ? "",
    run-command,
  }: {
    "${name}-start" = ''
      if [ ! -f "${pid-file}" ]; then
        if [ ! -d "${dir}" ]; then
          mkdir -p "${dir}"
          ${init-command}
          echo "initialized ${name} in '${dir}'"
        fi
        ${run-command}
        echo "started ${name} "
      else
        echo "${name} is already running with pid ${my-lib.cat pid-file}!"
      fi
    '';
    "${name}-stop" = ''
      if [ -f "${pid-file}" ]; then
        kill -QUIT "${my-lib.cat pid-file}"
        echo "stopped ${name}"
      else
        echo "${name} isn't running!"
      fi
    '';
  };
}
