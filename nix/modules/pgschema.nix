{pkgs, ...}: let
in
  pkgs.buildGoModule rec {
    pname = "pgschema";
    version = "1.9.0";
    src = pkgs.fetchFromGitHub {
      owner = "pgplex";
      repo = "pgschema";
      rev = "v${version}";
      hash = "sha256-SQ1zNLe6uLcHZO+hC14p6DJj+r/MUWrOqoDnIsFhLlc=";
    };
    vendorHash = "sha256-3nV7AEsWyEvIbxHetoEsA8PPXJ6ENvU/sz7Wn5aysss=";
    subPackages = ["."];
    proxyVendor = true;
  }
