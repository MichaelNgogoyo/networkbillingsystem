# Minimal MikroTik hotspot setup template.
# WARNING: review and adapt to your LAN names/subnets first.
# Replace placeholders before use.

# Example placeholders:
# BRIDGE_LAN=bridge-lan
# HOTSPOT_SUBNET=192.168.88.0/24
# HOTSPOT_GATEWAY=192.168.88.1
# POOL_START=192.168.88.10
# POOL_END=192.168.88.250

/ip pool add name=hs-pool ranges=POOL_START-POOL_END
/ip address add address=HOTSPOT_GATEWAY/24 interface=BRIDGE_LAN
/ip dhcp-server add name=hs-dhcp interface=BRIDGE_LAN address-pool=hs-pool disabled=no
/ip dhcp-server network add address=HOTSPOT_SUBNET gateway=HOTSPOT_GATEWAY dns-server=1.1.1.1,8.8.8.8
/ip hotspot profile add name=hsprof1 hotspot-address=HOTSPOT_GATEWAY html-directory=hotspot
/ip hotspot add name=hotspot1 interface=BRIDGE_LAN address-pool=hs-pool profile=hsprof1

# NAT if missing
/ip firewall nat add chain=srcnat action=masquerade out-interface-list=WAN comment="Hotspot internet"

# Verify
/ip hotspot print detail
/ip dhcp-server print detail
