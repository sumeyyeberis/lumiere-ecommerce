FROM dunglas/frankenphp
RUN install-php-extensions mysqli
COPY . /app/public/
