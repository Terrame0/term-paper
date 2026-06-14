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
    pkgs = import nixpkgs {inherit system;};
    lib = pkgs.lib;
    my-lib =
      lib.foldl (acc: path: acc // (import path args)) {}
      (lib.filesystem.listFilesRecursive ./nix/my-lib);
    args = {
      inherit pkgs;
      inherit lib;
      inherit my-lib;
    };
    pgschema = import ./nix/modules/pgschema.nix args;
    modules = lib.forEach (lib.filesystem.listFilesRecursive ./nix/modules) (
      path:
        import path ({
            server-config = import ./server-config.nix;
            tmp-dir = "/tmp/term-project";
            
            flake-root = lib.traceValSeq self.outPath;
          }
          // args)
    );
    server = my-lib.mk-script-union {
      pname = "server";
      deps = modules;
      scripts = {
        server-start = ''
          postgres-start
          schema-apply
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
  in {
    devShells.${system}.default = pkgs.mkShell {
      name = "web-stack";
      buildInputs = [server pkgs.postgresql pgschema] ++ modules;
    };
  };
}
