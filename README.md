# Telegram WordPress Master Bot & WordPress Connector Plugin

## Overview
This project provides a two-component management system that allows administrators to manage WordPress websites through Telegram.

### Components
1. **telegram_wordpress_bot.php** – Telegram bot script handling commands, authentication, and communication.
2. **wp-telegram-connector.php** – WordPress plugin providing authenticated REST API endpoints.

## Features
- Telegram command dispatcher
- Admin authentication
- SQLite persistent storage
- WordPress plugin/theme management
- Secure REST API integration
- Logging and debug mode

## Installation
### Telegram Bot
1. Create bot via BotFather.
2. Insert BOT_TOKEN and ADMIN_ID in telegram_wordpress_bot.php.
3. Configure WP_API_URL and WP_API_TOKEN.

### WordPress Plugin
1. Upload plugin folder into wp-content/plugins/.
2. Activate plugin.
3. Generate API token in settings.

## Usage
Use Telegram commands to manage plugins, themes, and query WP site information.

## Architecture
Telegram Bot → REST API → WordPress Connector Plugin

## License
MIT
