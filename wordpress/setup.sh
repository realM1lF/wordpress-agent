#!/bin/bash

# Setup script for WordPress dev environment

set -e

echo "🚀 Setting up Levi WordPress Dev Environment..."

# Check if ddev is installed
if ! command -v ddev &> /dev/null; then
    echo "❌ DDEV is not installed. Please install DDEV first:"
    echo "   https://ddev.com/get-started/"
    exit 1
fi

# Create web directory
mkdir -p web/wp-content/plugins
mkdir -p web/wp-content/uploads

# Create symlink to Levi plugin
if [ ! -L "web/wp-content/plugins/levi-agent" ]; then
    echo "🔗 Creating symlink for Levi plugin..."
    ln -s ../../../.. web/wp-content/plugins/levi-agent
fi

# Start DDEV
echo "🐳 Starting DDEV..."
ddev start

echo ""
echo "✅ Setup complete!"
echo ""
echo "📍 URLs:"
echo "   Website: https://levi-wordpress.ddev.site"
echo "   Admin:   https://levi-wordpress.ddev.site/wp-admin/"
echo "   User:    admin"
echo "   Pass:    admin"
echo ""
echo "📍 Plugin development:"
echo "   The plugin is symlinked to: web/wp-content/plugins/levi-agent"
echo "   Activate it in: Plugins → Installed Plugins"
echo ""
