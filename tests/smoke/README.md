# Smoke tests

WordPress is stubbed out, so these run anywhere with PHP 7.4+ and Node.

```bash
php tests/smoke/php-harness.php        # or /Applications/MAMP/bin/php/php7.4.2/bin/php
node tests/smoke/js-harness.js
```

They exercise channel classification, cookie sanitisation, contact extraction, settings validation, lead dispatch/queue/retry, and the tracker.js attribution + event logic. Not a substitute for testing on a real site with real form plugins.
