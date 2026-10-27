# YouCam VirtualStyle AI — Hackathon 2026

Five-language virtual clothing try-on web app for **Team BaharSoft BD**. Default language: English. Includes 5 dark themes, 1200 × 700 centered interface, full-photo preview, Live YouCam Clothes V4, Dashboard, local History, saved Wardrobe, rule-based Style Advisor, Settings and About.

## Requirements

PHP 8.0+ with cURL and fileinfo; HTTPS domain and an active YouCam AI API key/credits. Node.js is not required.

## Deploy on cPanel

1. Upload `index.html`, `api.php`, and the `uploads` folder to `public_html/virtualstyle.baharsoftbd.com/` (preserve existing `.htaccess`, `.user.ini`, and `php.ini`).
2. Outside `public_html` create `/home/baharsof/youcam-config.php`, containing:

   ```php
   <?php
   return ['api_key' => 'YOUR_NEW_KEY'];
   ```

   **Never commit or publicly upload this file.** Rotate any previously exposed API key.
3. Ensure `uploads/` is writable by PHP and reachable over HTTPS (image uploads are sent to YouCam by public HTTPS URL).
4. Open `https://virtualstyle.baharsoftbd.com` and select a JPG/PNG person photo and reference garment photo, then select Try On.

## What is real vs. local

- Try On calls the YouCam Clothes V4 endpoint through server-side PHP.
- History & Wardrobe save only generated result URLs in browser localStorage; images may expire. These are not server accounts or databases.
- Style Advisor is simple rule-based guidance, **not AI recommendations**.
- Results can use API units. The site requires a secure, valid backend config.

## Privacy and hardening

The PHP upload handler stores randomized JPG/PNG filenames in `uploads/` to make their files accessible to YouCam. Photos are publicly reachable by anyone with their random URL. **Do not use sensitive photos** with this prototype. Implement retention/deletion, access controls, upload rate limits, and abuse protection before broad public launch. The included `uploads/.htaccess` blocks executable script files. Never upload the API key under public_html.

## Source and attribution

Team BaharSoft BD. Uses third-party YouCam API by Perfect Corp. This is an independent hackathon project, not an official YouCam product.
