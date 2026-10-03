#!/usr/bin/env bash
# sorgu.co/fat kurulum ve güncelleme betiği. root olarak çalıştırılır:
#   curl -fsSL https://raw.githubusercontent.com/offroadist/fatura/claude/nj-rv8gxn/deploy/sorgu/kur.sh | sudo bash
# Tekrar çalıştırılabilir: kod güncellenir, servis yeniden başlatılır; mevcut fatura.env korunur.
set -euo pipefail

REPO="https://github.com/offroadist/fatura.git"
BRANCH="${FATURA_BRANCH:-claude/nj-rv8gxn}"
APP_DIR=/opt/fatura
ENV_DIR=/etc/fatura

if ! command -v node >/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 18 ]; then
  echo "Node.js 18+ gerekli. Debian/Ubuntu için:"
  echo "  curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && apt-get install -y nodejs"
  exit 1
fi

id -u fatura >/dev/null 2>&1 || useradd --system --home "$APP_DIR" --shell /usr/sbin/nologin fatura

if [ -d "$APP_DIR/.git" ]; then
  git -C "$APP_DIR" fetch -q origin "$BRANCH"
  git -C "$APP_DIR" checkout -q -B "$BRANCH" "origin/$BRANCH"
else
  git clone -q --branch "$BRANCH" "$REPO" "$APP_DIR"
fi
(cd "$APP_DIR" && npm ci --omit=dev --no-audit --no-fund 2>/dev/null || npm install --omit=dev --no-audit --no-fund)
chown -R fatura:fatura "$APP_DIR"

mkdir -p "$ENV_DIR"
if [ ! -f "$ENV_DIR/fatura.env" ]; then
  sed "s/^COOKIE_SECRET=.*/COOKIE_SECRET=$(openssl rand -hex 32)/" "$APP_DIR/deploy/sorgu/fatura.env.example" > "$ENV_DIR/fatura.env"
  chmod 600 "$ENV_DIR/fatura.env"
  echo "Yeni ayar dosyası: $ENV_DIR/fatura.env (GİB TEST ortamı açık; canlı için FATURA_TEST satırını silin)"
fi

install -m 644 "$APP_DIR/deploy/sorgu/fatura.service" /etc/systemd/system/fatura.service
systemctl daemon-reload
systemctl enable -q fatura
systemctl restart fatura
sleep 1
systemctl --no-pager --lines=5 status fatura || true

echo
echo "Yerel kontrol:"
curl -s -o /dev/null -w '  GET /fat/        -> %{http_code}\n' http://127.0.0.1:3000/fat/
curl -s -o /dev/null -w '  GET /fat/api/me  -> %{http_code} (401 beklenir)\n' http://127.0.0.1:3000/fat/api/me
echo
echo "nginx: deploy/sorgu/nginx-fat.conf içindeki location bloğunu sorgu.co server bloğuna ekleyin,"
echo "       sonra: nginx -t && systemctl reload nginx"
echo "Panel: https://sorgu.co/fat"
