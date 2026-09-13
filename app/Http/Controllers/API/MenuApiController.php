<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MenuApiController extends Controller
{
    /**
     * Get the admin menu structure
     */
    public function getMenu(Request $request)
    {
        // Return menu without authentication requirement
        // Browser will pass token in query param if needed
        
        // Base menu items (always available to admins)
        $menuItems = [
            [
                'name' => 'Dashboard',
                'href' => '/dashboard',
                'slug' => 'link',
                'icon' => 'fas fa-gauge',
            ],
            [
                'name' => 'Orders',
                'href' => '/orders',
                'slug' => 'link',
                'icon' => 'fas fa-boxes',
            ],
            [
                'name' => 'Catalog',
                'slug' => 'dropdown',
                'href' => '#',
                'icon' => 'fas fa-layer-group',
                'elements' => [
                    [
                        'name' => 'Products',
                        'href' => '/products',
                        'slug' => 'link',
                        'icon' => 'fas fa-box',
                    ],
                    [
                        'name' => 'Categories',
                        'href' => '/manage_categories',
                        'slug' => 'link',
                        'icon' => 'fas fa-sitemap',
                    ],
                    [
                        'name' => 'Brands',
                        'href' => '/brands',
                        'slug' => 'link',
                        'icon' => 'fas fa-trademark',
                    ],
                    [
                        'name' => 'Attributes',
                        'href' => '/attributes',
                        'slug' => 'link',
                        'icon' => 'fas fa-sliders-h',
                    ],
                ],
            ],
            [
                'name' => 'Customers',
                'href' => '/customers',
                'slug' => 'link',
                'icon' => 'fas fa-users',
            ],
            [
                'name' => 'Delivery',
                'slug' => 'dropdown',
                'href' => '#',
                'icon' => 'fas fa-truck',
                'elements' => [
                    [
                        'name' => 'Delivery Boys',
                        'href' => '/delivery_boys',
                        'slug' => 'link',
                        'icon' => 'fas fa-person',
                    ],
                    [
                        'name' => 'Cash Collection',
                        'href' => '/cash_collection',
                        'slug' => 'link',
                        'icon' => 'fas fa-money-bill',
                    ],
                ],
            ],
            // **DOCTOR MANAGEMENT SECTION** - Added before Administration
            [
                'name' => 'Doctor Management',
                'slug' => 'dropdown',
                'href' => '#',
                'icon' => 'fas fa-stethoscope',
                'elements' => [
                    [
                        'name' => 'Doctors',
                        'href' => '/doctors',
                        'slug' => 'link',
                        'icon' => 'fas fa-user-md',
                    ],
                    [
                        'name' => 'Clinics',
                        'href' => '/clinics',
                        'slug' => 'link',
                        'icon' => 'fas fa-hospital',
                    ],
                    [
                        'name' => 'Appointments',
                        'href' => '/appointments',
                        'slug' => 'link',
                        'icon' => 'fas fa-calendar-check',
                    ],
                    [
                        'name' => 'Prescriptions',
                        'href' => '/prescriptions',
                        'slug' => 'link',
                        'icon' => 'fas fa-prescription-bottle',
                    ],
                    [
                        'name' => 'Financial',
                        'href' => '/doctor_financials',
                        'slug' => 'link',
                        'icon' => 'fas fa-wallet',
                    ],
                ],
            ],
            [
                'name' => 'Reports',
                'href' => '/reports/sales',
                'slug' => 'link',
                'icon' => 'fas fa-chart-bar',
            ],
            [
                'name' => 'Chat',
                'href' => '/chat',
                'slug' => 'link',
                'icon' => 'fas fa-comments',
            ],
            [
                'name' => 'Administration',
                'slug' => 'dropdown',
                'href' => '#',
                'icon' => 'fas fa-cog',
                'elements' => [
                    [
                        'name' => 'System Users',
                        'href' => '/system_users',
                        'slug' => 'link',
                        'icon' => 'fas fa-users-cog',
                    ],
                    [
                        'name' => 'Roles',
                        'href' => '/role',
                        'slug' => 'link',
                        'icon' => 'fas fa-shield',
                    ],
                    [
                        'name' => 'Countries',
                        'href' => '/countries',
                        'slug' => 'link',
                        'icon' => 'fas fa-globe',
                    ],
                    [
                        'name' => 'Settings',
                        'href' => '/settings',
                        'slug' => 'link',
                        'icon' => 'fas fa-sliders-h',
                    ],
                ],
            ],
        ];

        return response()->json($menuItems);
    }
}
