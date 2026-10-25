FROM dunglas/frankenphp:1.13.3-php8.5-bookworm@sha256:572f34f012de7de134f2bfb9528dfeeadc03d808e3c944676fde4d1659cc51f8

RUN install-php-extensions intl
COPY config/frankenphp.ini /usr/local/etc/php/conf.d/interact-demo.ini
WORKDIR /app

CMD ["php", "tests/test_app/bin/cake.php", "server", "--frankenphp", "--host", "0.0.0.0", "--port", "8765", "--document_root", "tests/test_app/webroot"]
