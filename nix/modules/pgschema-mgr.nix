{
  pkgs,
  my-lib,
  server-config,
  flake-root,
  ...
}: let
  pgschema = import ./pgschema.nix {inherit pkgs;};
in
  with server-config;
    my-lib.mk-script-union {
      pname = "pgschema-mgr";
      deps = [
        pkgs.postgresql
        pgschema
      ];
      scripts = {
        schema-apply = ''
          # -- ensure role exists
          if ! psql -h "${pg-socket-dir}" -d postgres -tAc \
              "SELECT 1 FROM pg_roles WHERE rolname = '${db-user}'" | grep -q 1; then
            createuser -h "${pg-socket-dir}" "${db-user}"
          fi

          # -- ensure database exists
          if ! psql -h "${pg-socket-dir}" -d postgres -tAc \
              "SELECT 1 FROM pg_database WHERE datname = '${db-name}'" | grep -q 1; then
            createdb -h "${pg-socket-dir}" -O "${db-user}" "${db-name}"
          fi

          # -- apply schema
          if out=$(pgschema apply \
                     --auto-approve \
                     --no-color \
                     --host "${pg-socket-dir}" \
                     --db "${db-name}" \
                     --user "${db-user}" \
                     --file "${flake-root}/db-ddl.sql" 2>&1); then
            if echo "$out" | grep -q "No changes"; then
              echo "schema for '${db-name}': up to date"
            else
              echo "schema for '${db-name}': applied"
            fi
          else
            echo "schema for '${db-name}': failed"
            echo "$out" >&2
            exit 1
          fi

          rm -f plan.json
        '';
      };
    }
