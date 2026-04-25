{
  pkgs,
  lib,
  ...
}: let
in {
  mk-script-union = {
    pname ? "scripts",
    scripts,
    env ? {},
    deps ? [],
  }:
    pkgs.symlinkJoin {
      name = pname;
      paths =
        lib.mapAttrsToList (
          name: text:
            pkgs.writeShellApplication {
              inherit name;
              inherit text;
              runtimeEnv = env;
              runtimeInputs = deps;
            }
        )
        scripts;
    };
}
