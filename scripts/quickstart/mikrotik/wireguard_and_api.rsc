# WireGuard + API hardening script template for MikroTik (RouterOS 7+)
# Replace all ALL_CAPS placeholders before running.

/interface wireguard add name=wg-vps private-key="MIKROTIK_PRIVATE_KEY"
/ip address add address=10.20.20.2/24 interface=wg-vps
/interface wireguard peers add \
  interface=wg-vps \
  public-key="VPS_PUBLIC_KEY" \
  endpoint-address=VPS_PUBLIC_IP \
  endpoint-port=51820 \
  allowed-address=10.20.20.1/32 \
  persistent-keepalive=25

# API user and group
/user group add name=phpnuxbill policy=api,read,write,test,password
/user add name=phpnuxbill password=API_PASSWORD group=phpnuxbill

# API service restricted to VPS tunnel IP
/ip service set api disabled=no port=8728 address=10.20.20.1/32
/ip service set api-ssl disabled=yes

# Firewall allow rule first
/ip firewall filter add chain=input action=accept protocol=tcp src-address=10.20.20.1 in-interface=wg-vps dst-port=8728 place-before=0 comment="Allow VPS PHPNuxBill API"

# Verify
/interface wireguard peers print detail
/ip service print where name~"api"
/ip firewall filter print where chain=input
