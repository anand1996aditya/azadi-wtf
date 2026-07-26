# azadi.wtf — Mirror Deployment Guide

## 1. Cloudflare Pages Mirror (5 minutes)

1. Go to https://dash.cloudflare.com → Workers & Pages → Create → Pages → Connect to Git
2. Select `anand1996aditya/azadi-wtf` repository
3. Build settings:
   - Build command: (leave empty — it's static HTML)
   - Output directory: `/`
4. Click "Save and Deploy"
5. You get a free `azadi-wtf.pages.dev` subdomain
6. Add custom domain `azadi.wtf` in Cloudflare Pages settings (optional)

Your site now runs behind Cloudflare's global network. Immune to most DDoS and takedown attempts.

---

## 2. Tor Onion Service (when you have a machine to run it)

Requirements: any Linux machine (Raspberry Pi, old laptop, $5 VPS)

```bash
# Install Tor
sudo apt install tor

# Edit /etc/tor/torrc — add these lines:
HiddenServiceDir /var/lib/tor/azadi/
HiddenServicePort 80 127.0.0.1:8080

# Restart Tor
sudo systemctl restart tor

# Get your .onion address
sudo cat /var/lib/tor/azadi/hostname
```

Serve the site files on port 8080 using Python:
```bash
cd /path/to/protest-site
python3 -m http.server 8080
```

Your site is now accessible at the `.onion` address. Unblockable via Tor Browser.

---

## 3. IPFS Pinning (can be done later)

Install IPFS Desktop from https://ipfs.tech — drag the `protest-site/` folder in.
Then pin via Pinata (https://pinata.cloud) for permanent hosting.

Your content lives on IPFS forever. No single server. No takedown possible.
