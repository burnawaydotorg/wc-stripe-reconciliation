# WooCommerce Stripe Reconciliation

A WordPress plugin that automatically checks and reconciles WooCommerce orders with Stripe payments when webhooks fail to properly update order status.

## Description

This plugin solves a common issue with WooCommerce and Stripe where sometimes webhooks don't reach your site, leaving orders in a "pending" or "on-hold" state even though the payment was successfully processed in Stripe.

The plugin works by:
1. Periodically checking recent pending/on-hold orders paid via Stripe
2. Connecting to the Stripe API to verify payment status
3. Automatically updating order status if payment was successful
4. Providing tools for manual reconciliation when needed

Perfect for sites that occasionally experience webhook delivery failures due to firewall settings, server configuration, or network issues.

## Features

- **Automated Reconciliation**: Checks pending/on-hold Stripe orders hourly
- **Manual Reconciliation Button**: Quickly check and update individual orders
- **Configurable Settings**: Control how many days of orders to check and how many orders to process
- **Detailed Logging**: Track all reconciliation activity (optional)
- **WooCommerce Integration**: Seamless integration with order management screens

## Installation

1. Upload the `wc-stripe-reconciliation` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce → Stripe Reconciliation to configure settings

## Requirements

- WordPress 5.0 or higher
- WooCommerce 4.0 or higher
- WooCommerce Stripe Gateway plugin

## Usage

### Automatic Reconciliation

The plugin will automatically check for missed payments hourly. No action required.

### Manual Reconciliation

#### For Individual Orders:
1. Go to WooCommerce → Orders
2. Find a pending/on-hold order processed with Stripe
3. Click the "Reconcile Stripe" button

#### For Bulk Reconciliation:
1. Go to WooCommerce → Stripe Reconciliation
2. Click "Run Reconciliation Now"

### Configuration

1. Navigate to WooCommerce → Stripe Reconciliation
2. Configure:
   - **Days to check**: How many days of past orders to examine
   - **Orders to check**: Maximum number of orders processed per run
   - **Enable logging**: Toggle detailed activity logging

## Troubleshooting

### Common Issues

- **"Stripe API not available" error**: Ensure the WooCommerce Stripe Gateway plugin is active
- **No orders being updated**: Verify your Stripe API connection is working
- **Plugin shows dependency warnings**: Make sure WooCommerce is activated

### Logs

Find detailed logs at WooCommerce → Status → Logs → stripe-reconciliation

## Changelog

### 1.0.1
- Fixed dependency check for WooCommerce
- Improved Stripe Gateway detection
- Added singleton pattern for better performance

### 1.0.0
- Initial release

## About

Developed for a nonprofit art magazine using WooCommerce on Azure. Created to solve webhook reliability issues with Stripe payment processing.
