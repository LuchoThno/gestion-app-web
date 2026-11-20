#!/usr/bin/env bash
# Levanta una demostración local con datos ficticios de OX3 y MRM.
# Crea una copia aparte en demo/.run (ignorada por git): no toca private/config.php ni ningún dato real.
#   ./demo/levantar.sh             → crea la demo (si no existe) y la abre en http://127.0.0.1:8080
#   ./demo/levantar.sh --reiniciar → borra la demo y la crea de nuevo
set -euo pipefail
cd "$(dirname "$0")/.."
RUN=demo/.run
PORT="${PORT:-8080}"
command -v php >/dev/null || { echo "Falta PHP 8.1 o superior en el PATH."; exit 1; }
MODS="$(php -m)"
for ext in pdo_sqlite sqlite3 fileinfo zip sodium dom; do
  grep -qix "$ext" <<<"$MODS" || { echo "Falta la extensión PHP: $ext"; exit 1; }
done
if [[ "${1:-}" == "--reiniciar" ]]; then rm -rf "$RUN"; fi
if [[ ! -f "$RUN/private/gestion.sqlite" ]]; then
  echo "Creando la demostración en $RUN ..."
  rm -rf "$RUN"; mkdir -p "$RUN/private"
  cp -R public_html "$RUN/"
  find private -maxdepth 1 -type f \( -name '*.php' -o -name '.htaccess' \) ! -name 'config.php' -exec cp {} "$RUN/private/" \;
  rnd() { php -r 'echo bin2hex(random_bytes(24));'; }
  sed -e "s|'setup_key' => ''|'setup_key' => '$(rnd)'|" \
      -e "s|'https_only' => true|'https_only' => false|" \
      -e "s|'app_url' => 'https://gestion.ox3.cl/'|'app_url' => 'http://127.0.0.1:$PORT/'|" \
      -e "s|'mfa_roles' => \['admin', 'editor'\]|'mfa_roles' => []|" \
      -e "s|'backup_key' => ''|'backup_key' => 'demo-$(rnd)'|" \
      -e "s|'backup_auto_hours' => 24|'backup_auto_hours' => 0|" \
      private/config.example.php > "$RUN/private/config.php"
  php demo/seed.php "$RUN/private" ox3
  php demo/seed.php "$RUN/private" mrm
fi
cat <<MSG

  Demo lista: http://127.0.0.1:$PORT
  Usuarios (contraseña Demo-Gestion-2026):
    admin@demo.test   administrador, ve todo
    editor@demo.test  editora
    lector@demo.test  solo lectura
  Detener: Ctrl+C. Reiniciar los datos: ./demo/levantar.sh --reiniciar

MSG
exec php -S "127.0.0.1:$PORT" -t "$RUN/public_html"
