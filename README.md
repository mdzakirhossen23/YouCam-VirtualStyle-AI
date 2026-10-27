# YouCam VirtualStyle AI

Secure PHP/JavaScript virtual clothes try-on studio powered by YouCam AI Clothes V4.

**Live demo:** https://virtualstyle.baharsoftbd.com/  
**Hackathon:** https://youcam-api-skin-ai-ecommerce.devpost.com/

## Features

- Person or prior result images can be selected independently for the first box.
- Garment images can be independently selected for the second box.
- Save photos and generated results to a private wardrobe, with version history and download.
- Refresh clears both selections and the displayed result without deleting saved wardrobe/history.
- Multilingual UI, dark themes, icons and favicon.
- Server-side API integration: keys are never shipped in the browser.

## Deploy

See [DEPLOYMENT.md](DEPLOYMENT.md) for cPanel deployment and PHP requirements.

**Important:** Never commit API keys, `.env` files, uploads or account data. Use a `YOUCAM_API_KEY` environment variable or a protected key file outside the web root. The example `config.php` included here contains no API key.

## Privacy

Do not publish customer photos, credentials, or files under `storage/`. GitHub source code is not the live image library.
