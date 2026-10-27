YouCam VirtualStyle AI — secure cPanel package

Start with DEPLOYMENT.md.

Important: this package intentionally contains no API secret. Revoke the older
key that was previously shared and configure a replacement only on the server.

Main files:
- index.html + assets/: multilingual UI, icons, favicon, five themes
- api.php + bootstrap.php + cron_cleanup.php: authentication, storage, gallery, history, export,
  validation, retention, and YouCam Clothes V4 integration
- config.php: server-only configuration (blank API key by design)
- storage/.htaccess: blocks direct access to private files
