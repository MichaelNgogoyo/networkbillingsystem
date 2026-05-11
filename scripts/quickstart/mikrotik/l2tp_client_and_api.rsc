# L2TP/IPsec + API hardening script template for MikroTik
# Replace all ALL_CAPS placeholders before running.

/interface l2tp-client add \
  name=l2tp-to-vps \
  connect-to=VPS_PUBLIC_IP \
  user=mikrotik \
  password=L2TP_PASSWORD \
  use-ipsec=yes \
  ipsec-secret=L2TP_PSK \
  allow=mschap2 \
  max-mtu=1410 \
  max-mru=1410 \
  disabled=no

# API user and group
/user group add name=phpnuxbill policy=api,read,write,test,password
/user add name=phpnuxbill password=API_PASSWORD group=phpnuxbill

# API service restricted to VPS tunnel IP
/ip service set api disabled=no port=8728 address=10.0.10.1/32
/ip service set api-ssl disabled=yes

# Firewall allow rule first
/ip firewall filter add chain=input action=accept protocol=tcp src-address=10.0.10.1 in-interface=l2tp-to-vps dst-port=8728 place-before=0 comment="Allow VPS PHPNuxBill API"

# Verify
/interface l2tp-client monitor l2tp-to-vps once
/ip service print where name~"api"
/ip firewall filter print where chain=input
