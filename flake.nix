{
  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-25.11";
  };
  outputs = {
    nixpkgs,
    self,
    ...
  }: let
    system = "x86_64-linux";
    pkgs = import nixpkgs {system = system;};
    lib = pkgs.lib;
    my-lib =
      lib.foldl (
        acc: path:
          acc
          // (import path {
            inherit pkgs;
            inherit lib;
            inherit my-lib;
          })
      ) {}
      (lib.filesystem.listFilesRecursive ./nix/my-lib);
    modules = lib.forEach (lib.filesystem.listFilesRecursive ./nix/modules) (
      path:
        import path {
          server-config = import ./server-config.nix;
          tmp-dir = "/tmp/term-project";
          inherit pkgs;
          inherit lib;
          inherit my-lib;
          flake-root = lib.traceValSeq self.outPath;
        }
    );
    server = my-lib.mk-script-union {
      pname = "server";
      deps = modules;
      scripts = {
        server-start = ''
          postgres-start
          php-fpm-start
          nginx-start
        '';
        server-stop = ''
          nginx-stop
          php-fpm-stop
          postgres-stop
        '';
      };
    };
    server-root =
      (import ./php/project.nix)
      {
        inherit pkgs;
        flake-root = self.outPath;
      };
  in {
    packages.${system}.default = server-root;
    devShells.${system}.default = pkgs.mkShell {
      name = "web-stack";
      buildInputs = [server pkgs.phpPackages.composer];
    };
  };
}
