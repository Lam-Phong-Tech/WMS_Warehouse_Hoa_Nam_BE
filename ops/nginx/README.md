# Cloudflare client IP on the production host

Install `cloudflare-realip.conf` as `/etc/nginx/conf.d/cloudflare-realip.conf` on the TLS-terminating Nginx host. This location is included from the `http` block. Run `nginx -t` before reloading Nginx.

The file trusts only Cloudflare's published source ranges for `CF-Connecting-IP`. Refresh the IPv4 and IPv6 ranges from the URLs in the file when Cloudflare changes them. Never replace the ranges with `0.0.0.0/0` or `::/0`: direct-origin requests must not be able to forge a visitor IP.

The API's Laravel trusted-proxy configuration then receives the restored address through `X-Forwarded-For`, so IP-based login and public API rate limits use the visitor address.
