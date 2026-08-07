# Hostinger Production Deployment

## Server access

| Item | Value |
|------|--------|
| SSH | `ssh u910121167_s2Y40pWr2@de-fra-h5g-node5.hstgr.io -p 65002` |
| App path | `~/websites/s2Y40pWr2/public_html/_optimus` |
| Document root | `~/websites/s2Y40pWr2/public_html/_optimus/public` |

## Fresh install

```bash
cd ~/websites/s2Y40pWr2/public_html
rm -rf _optimus
rm -f index.php .htaccess
git clone https://github.com/jdflores22/optimus-portal.git _optimus
bash _optimus/scripts/deploy-fresh-hostinger.sh --wipe
cd _optimus
ln -sf ../.env.local .env.local
```

Set **hPanel Document Root** to `public_html/_optimus/public`.

## Update deploy

```bash
cd ~/websites/s2Y40pWr2/public_html/_optimus
bash scripts/deploy-update-hostinger.sh
```

## Email not sending? (messenger queue)

Registration and notification emails were queued in `messenger_messages` when no worker was running.

**One-time flush (send stuck emails now):**

```bash
cd ~/websites/s2Y40pWr2/public_html/_optimus
php bin/console messenger:consume async --env=prod --limit=100 -vv
```

**After deploy with latest code:** prod sends mail immediately (no queue). Clear cache after pull:

```bash
php bin/console cache:clear --env=prod
```

Check failed messages:

```bash
php bin/console messenger:failed:show --env=prod
```
