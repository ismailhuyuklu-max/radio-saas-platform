#!/usr/bin/env bash
# =============================================================================
# AdCast Pro — Ubuntu 24.04 VDS Bootstrap (Faz REBRAND-DEPLOY)
# =============================================================================
# Sıfırdan bir Ubuntu 24.04 VDS'i AdCast Pro çalıştırmaya hazır hale getirir.
# Çalıştır: bash bin/server-bootstrap.sh
# Gereksinim: root yetkisi (sudo veya doğrudan root user).
#
# Yaptıkları:
#  1. apt update + upgrade + tüm güvenlik patch'leri
#  2. Temel paketler (curl, git, ca-certificates, jq, unzip, ufw, fail2ban)
#  3. Swap dosyası (4GB) — 8GB RAM'lik VDS için OOM koruması
#  4. Docker Engine + Compose plugin (resmi repo)
#  5. Firewall (UFW) — sadece 22, 80, 443 dış erişim
#  6. fail2ban — SSH brute force koruması (5 hatalı = 1h ban)
#  7. unattended-upgrades — otomatik security patch
#  8. SSH hardening — root login key-only, password auth OFF, MaxAuthTries=3
#  9. Logrotate — Docker daemon log
#  10. Timezone Europe/Istanbul, locale tr_TR.UTF-8
#
# Çıktı: /var/log/adcast-bootstrap.log
# =============================================================================

set -euo pipefail
exec > >(tee -a /var/log/adcast-bootstrap.log) 2>&1
echo "=== AdCast Pro Server Bootstrap — $(date -Iseconds) ==="

# Root check
[[ $EUID -ne 0 ]] && { echo "HATA: root olarak çalıştırın (sudo bash $0)"; exit 1; }

# ---------- 1. APT update + upgrade ----------
echo "[1/10] APT update + upgrade..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get -y -qq upgrade
apt-get -y -qq dist-upgrade

# ---------- 2. Temel paketler ----------
echo "[2/10] Temel paketler..."
apt-get install -y -qq \
  ca-certificates curl git jq unzip htop tmux vim \
  ufw fail2ban unattended-upgrades \
  net-tools dnsutils tzdata locales \
  apt-transport-https gnupg lsb-release

# ---------- 3. Swap (4GB) ----------
echo "[3/10] Swap dosyası..."
if ! swapon --show | grep -q /swapfile; then
  fallocate -l 4G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  echo '/swapfile none swap sw 0 0' >> /etc/fstab
  echo 'vm.swappiness=10' > /etc/sysctl.d/99-swappiness.conf
  sysctl --system >/dev/null
  echo "  ✓ 4GB swap aktif"
else
  echo "  ✓ Swap zaten var"
fi

# ---------- 4. Docker Engine + Compose ----------
echo "[4/10] Docker Engine + Compose..."
if ! command -v docker &>/dev/null; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -qq
  apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  systemctl enable --now docker
  echo "  ✓ Docker $(docker --version | awk '{print $3}' | tr -d ,) kuruldu"
else
  echo "  ✓ Docker zaten var"
fi

# ---------- 4b. Node.js 20 LTS (frontend build için) ----------
echo "[4b/10] Node.js 20 LTS..."
if ! command -v node &>/dev/null || [[ $(node --version | sed 's/v//' | cut -d. -f1) -lt 18 ]]; then
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null 2>&1
  apt-get install -y -qq nodejs
  echo "  ✓ Node $(node --version), npm $(npm --version)"
else
  echo "  ✓ Node $(node --version) zaten var"
fi

# Docker daemon log rotation
cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  },
  "live-restore": true
}
EOF
systemctl reload docker || systemctl restart docker

# ---------- 5. Firewall (UFW) ----------
echo "[5/10] Firewall (UFW)..."
ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment 'SSH'
ufw allow 80/tcp comment 'HTTP (Caddy ACME challenge + redirect)'
ufw allow 443/tcp comment 'HTTPS'
# Docker host network kuralları
ufw --force enable
echo "  ✓ UFW aktif — 22, 80, 443 açık, diğerleri kapalı"

# ---------- 6. fail2ban ----------
echo "[6/10] fail2ban..."
cat > /etc/fail2ban/jail.local <<'EOF'
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5
banaction = ufw
ignoreip = 127.0.0.1/8 ::1

[sshd]
enabled = true
port = ssh
filter = sshd
logpath = /var/log/auth.log
maxretry = 3
bantime = 7200
EOF
systemctl enable --now fail2ban
systemctl reload fail2ban || systemctl restart fail2ban
echo "  ✓ fail2ban aktif (SSH: 3 hatalı → 2 saat ban)"

# ---------- 7. unattended-upgrades ----------
echo "[7/10] unattended-upgrades..."
dpkg-reconfigure -plow unattended-upgrades || true
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF
echo "  ✓ Otomatik güvenlik patch'leri aktif"

# ---------- 8. SSH hardening ----------
echo "[8/10] SSH hardening..."
# Backup orijinal
[[ ! -f /etc/ssh/sshd_config.bak ]] && cp /etc/ssh/sshd_config /etc/ssh/sshd_config.bak
# Key-only auth (ANCAK authorized_keys VARSA — kilitleyip dışarıda kalmamak için)
if [[ -s /root/.ssh/authorized_keys ]]; then
  sed -i 's/^#*PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
  sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
  sed -i 's/^#*ChallengeResponseAuthentication.*/ChallengeResponseAuthentication no/' /etc/ssh/sshd_config
  sed -i 's/^#*UsePAM.*/UsePAM yes/' /etc/ssh/sshd_config
  sed -i 's/^#*MaxAuthTries.*/MaxAuthTries 3/' /etc/ssh/sshd_config
  sed -i 's/^#*ClientAliveInterval.*/ClientAliveInterval 300/' /etc/ssh/sshd_config
  sed -i 's/^#*ClientAliveCountMax.*/ClientAliveCountMax 2/' /etc/ssh/sshd_config
  systemctl reload sshd
  echo "  ✓ SSH key-only auth aktif (password auth KAPALI)"
else
  echo "  ⚠ authorized_keys boş — SSH password auth açık BIRAKILDI (kilitlenmemen için)"
  echo "     Key ekledikten sonra elle: sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config && systemctl reload sshd"
fi

# ---------- 9. Logrotate (genel) ----------
echo "[9/10] Logrotate..."
cat > /etc/logrotate.d/adcast <<'EOF'
/var/log/adcast-*.log {
    weekly
    rotate 12
    compress
    delaycompress
    notifempty
    missingok
    create 0640 root root
}
EOF
echo "  ✓ /var/log/adcast-*.log için 12 hafta retention"

# ---------- 10. Timezone + locale ----------
echo "[10/10] Timezone + locale..."
timedatectl set-timezone Europe/Istanbul
locale-gen tr_TR.UTF-8 en_US.UTF-8 >/dev/null
update-locale LANG=tr_TR.UTF-8
echo "  ✓ Timezone: $(timedatectl show -p Timezone --value)"

# ---------- Özet ----------
echo
echo "=========================================="
echo "  AdCast Pro Bootstrap TAMAMLANDI"
echo "=========================================="
echo "  Sunucu: $(hostname) — $(uname -r)"
echo "  Docker: $(docker --version | awk '{print $3}' | tr -d ,)"
echo "  Compose: $(docker compose version --short 2>/dev/null || echo "n/a")"
echo "  UFW: $(ufw status | head -1)"
echo "  fail2ban: $(systemctl is-active fail2ban)"
echo "  Swap: $(free -h | awk '/^Swap:/ {print $2}')"
echo "  TZ: $(timedatectl show -p Timezone --value)"
echo
echo "Sonraki adım: bin/deploy-prod.sh"
echo "=========================================="
