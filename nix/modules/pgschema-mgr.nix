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
          # -- act as os superuser for role/db creation (ignore env PGUSER)
          unset PGUSER

          # -- ensure role exists
          if ! psql -h "${pg-socket-dir}" -d postgres -tAc \
              "SELECT 1 FROM pg_roles WHERE rolname = '${db-user}'" | grep -q 1; then
            createuser -h "${pg-socket-dir}" "${db-user}"
          fi

          # -- ensure databases exist
          for db in "${production-db-name}" "${test-db-name}"; do
            if ! psql -h "${pg-socket-dir}" -d postgres -tAc \
                "SELECT 1 FROM pg_database WHERE datname = '$db'" | grep -q 1; then
              createdb -h "${pg-socket-dir}" -O "${db-user}" "$db"
            fi
          done

          # -- apply schemas
          apply_one() {
            local db="$1" file="$2"
            if out=$(pgschema apply \
                       --auto-approve \
                       --no-color \
                       --host "${pg-socket-dir}" \
                       --db "$db" \
                       --user "${db-user}" \
                       --file "$file" 2>&1); then
              if echo "$out" | grep -q "No changes"; then
                echo "schema for '$db': up to date"
              else
                echo "schema for '$db': applied"
              fi
            else
              echo "schema for '$db': failed"
              echo "$out" >&2
              exit 1
            fi
          }

          apply_one "${production-db-name}" "${flake-root}/production-db-ddl.sql"
          apply_one "${test-db-name}"       "${flake-root}/test-db-ddl.sql"

          rm -f plan.json
        '';
      };
    }
