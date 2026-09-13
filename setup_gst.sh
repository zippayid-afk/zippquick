#!/bin/bash

# GST Setup Script for Laravel Application
# This script automates the GST implementation setup

echo "=========================================="
echo "   GST Implementation Setup Script"
echo "=========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if we're in the correct directory
if [ ! -f "artisan" ]; then
    echo -e "${RED}Error: artisan file not found!${NC}"
    echo "Please run this script from the Laravel application root directory."
    exit 1
fi

echo "Step 1: Running database migrations..."
php artisan migrate --force

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Migrations completed successfully${NC}"
else
    echo -e "${RED}✗ Migration failed${NC}"
    exit 1
fi

echo ""
echo "Step 2: Verifying GST Helper class..."
if [ -f "app/Helpers/GSTHelper.php" ]; then
    echo -e "${GREEN}✓ GSTHelper class found${NC}"
else
    echo -e "${YELLOW}! GSTHelper class not found${NC}"
    echo "  Please ensure app/Helpers/GSTHelper.php exists"
fi

echo ""
echo "Step 3: Configuration check..."
echo ""
echo "Please configure the following settings in your database or admin panel:"
echo ""
echo -e "${YELLOW}Required:${NC}"
echo "  - company_state (e.g., 'Maharashtra')"
echo ""
echo -e "${YELLOW}Recommended:${NC}"
echo "  - company_gstin (15-character GSTIN)"
echo "  - company_pan (10-character PAN)"
echo ""
echo "You can run this SQL to configure:"
echo ""
echo -e "${GREEN}UPDATE settings SET value = 'Maharashtra' WHERE variable = 'company_state';${NC}"
echo -e "${GREEN}UPDATE settings SET value = '27AAAAA0000A1Z5' WHERE variable = 'company_gstin';${NC}"
echo -e "${GREEN}UPDATE settings SET value = 'AAAAA0000A' WHERE variable = 'company_pan';${NC}"
echo ""

echo "Step 4: Checking database tables..."
php artisan db:show --table=orders 2>/dev/null | grep -q "cgst_amount" 
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Orders table has GST fields${NC}"
else
    echo -e "${YELLOW}! Orders table may not have GST fields${NC}"
fi

php artisan db:show --table=order_items 2>/dev/null | grep -q "cgst_amount"
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Order items table has GST fields${NC}"
else
    echo -e "${YELLOW}! Order items table may not have GST fields${NC}"
fi

php artisan db:show --table=products 2>/dev/null | grep -q "hsn_code"
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Products table has GST fields${NC}"
else
    echo -e "${YELLOW}! Products table may not have GST fields${NC}"
fi

echo ""
echo "=========================================="
echo "   Setup Complete!"
echo "=========================================="
echo ""
echo "Next Steps:"
echo "1. Configure company GST settings (company_state, company_gstin, company_pan)"
echo "2. Update products with HSN codes and GST rates"
echo "3. Test order creation and invoice generation"
echo ""
echo "For detailed instructions, see: GST_IMPLEMENTATION_GUIDE.md"
echo ""
