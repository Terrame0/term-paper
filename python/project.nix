{pkgs, ...}:
pkgs.python3Packages.buildPythonApplication {
  pname = "db-tester";
  version = "0.1.0";
  pyproject = true;
  src = ./.;
  build-system = [pkgs.python3Packages.setuptools];
  dependencies = [pkgs.python3Packages.asyncpg];
}
