{
  pkgs,
  flake-root,
}: let
  pname = "php-server";
  project-root = pkgs.php.buildComposerProject2 (finalAttrs: {
    inherit pname;
    version = "1.0.0";
    src = "${flake-root}/php";
    vendorHash = "sha256-DKcnIMk15E9vsw3b8W3YwWX7n4DhnTFVExEvtpF3eOs=";
  });
in "${project-root}/share/php/${pname}"
