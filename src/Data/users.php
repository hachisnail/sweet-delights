<?php

return array (
  0 => 
  array (
    'id' => 1,
    'name' => 'Mayie',
    'email' => 'mayie@example.com',
    'password_hash' => '$2y$10$qbDw3KQZQz3b7EgetOqonuudMfxYnd7ZabYiABQDLEkor5XBl7zC2',
    // password123
    'role' => 'admin',
    'cart' => 
    array (
      0 => 
      array (
        'id' => 2,
        'name' => 'Chocolate Donut',
        'image' => '/Assets/Products/Chocolate Donut.png',
        'price' => 180,
        'selectedSize' => 'Large',
        'stock' => 12,
        'quantity' => 2,
      ),
      1 => 
      array (
        'id' => 4,
        'name' => 'Vanilla Donut',
        'image' => '/Assets/Products/Vanilla Donut.png',
        'price' => 520,
        'selectedSize' => 'Mini',
        'stock' => 8,
        'quantity' => 3,
      ),
      2 => 
      array (
        'id' => 3,
        'name' => 'Strawberry Chocolate Cupcake',
        'image' => '/Assets/Products/Strawberry Chocolate Cupcake.png',
        'price' => 520,
        'selectedSize' => 'Box of 6',
        'stock' => 3,
        'quantity' => 2,
      ),
    ),
    'favourites' => 
    array (
    ),
  ),
  1 => 
  array (
    'id' => 2,
    'name' => 'Jeffe',
    'email' => 'jeffe@example.com',
    'password_hash' => '$2y$10$zdU0.LHJHyrGUg4mvqlgEuMmBIMcUiIbT/9Xj8XMBPKPKr7VaIlUi',
    // admin
    'role' => 'customer',
    'cart' => 
    array (
    ),
    'favourites' => 
    array (
    ),
  ),
  2 => 
  array (
    'id' => 3,
    'name' => 'SysAdmin',
    'email' => 'super@example.com',
    'password_hash' => '$2y$10$CrEKRg/JKZG.uEQYvNtli.k1bXbbNGicTDv54cTqh.TY.yX19TKZq',
    // superpass
    'role' => 'superadmin',
    'cart' => 
    array (
    ),
    'favourites' => 
    array (
    ),
  ),
);
