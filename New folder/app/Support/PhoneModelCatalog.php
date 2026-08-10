<?php

namespace App\Support;

class PhoneModelCatalog
{
    public static function brands(): array
    {
        return [
            'Apple (iPhone)' => [
                'iPhone 6', 'iPhone 6 Plus', 'iPhone 6s', 'iPhone 6s Plus',
                'iPhone 7', 'iPhone 7 Plus',
                'iPhone 8', 'iPhone 8 Plus', 'iPhone SE 2020', 'iPhone SE 2022',
                'iPhone X', 'iPhone XR', 'iPhone XS', 'iPhone XS Max',
                'iPhone 11', 'iPhone 11 Pro', 'iPhone 11 Pro Max',
                'iPhone 12', 'iPhone 12 mini', 'iPhone 12 Pro', 'iPhone 12 Pro Max',
                'iPhone 13', 'iPhone 13 mini', 'iPhone 13 Pro', 'iPhone 13 Pro Max',
                'iPhone 14', 'iPhone 14 Plus', 'iPhone 14 Pro', 'iPhone 14 Pro Max',
                'iPhone 15', 'iPhone 15 Plus', 'iPhone 15 Pro', 'iPhone 15 Pro Max',
                'iPhone 16', 'iPhone 16 Plus', 'iPhone 16 Pro', 'iPhone 16 Pro Max',
                'iPhone 17', 'iPhone 17 Air', 'iPhone 17 Pro', 'iPhone 17 Pro Max',
            ],

            'Samsung' => [
                'Galaxy A01', 'Galaxy A02', 'Galaxy A02s', 'Galaxy A03', 'Galaxy A03s', 'Galaxy A04', 'Galaxy A04e', 'Galaxy A04s',
                'Galaxy A05', 'Galaxy A05s', 'Galaxy A06',
                'Galaxy A10', 'Galaxy A10s', 'Galaxy A11', 'Galaxy A12', 'Galaxy A13', 'Galaxy A13 5G',
                'Galaxy A14 4G', 'Galaxy A14 5G',
                'Galaxy A15 4G', 'Galaxy A15 5G',
                'Galaxy A20', 'Galaxy A20s', 'Galaxy A21s', 'Galaxy A22 4G', 'Galaxy A22 5G',
                'Galaxy A23 4G', 'Galaxy A23 5G',
                'Galaxy A24', 'Galaxy A25',
                'Galaxy A30', 'Galaxy A30s', 'Galaxy A31', 'Galaxy A32 4G', 'Galaxy A32 5G', 'Galaxy A33', 'Galaxy A34', 'Galaxy A35',
                'Galaxy A40', 'Galaxy A41', 'Galaxy A42 5G',
                'Galaxy A50', 'Galaxy A50s', 'Galaxy A51', 'Galaxy A52', 'Galaxy A52s 5G', 'Galaxy A53', 'Galaxy A54', 'Galaxy A55', 'Galaxy A56',
                'Galaxy A70', 'Galaxy A71', 'Galaxy A72', 'Galaxy A73',

                'Galaxy S8', 'Galaxy S8 Plus',
                'Galaxy S9', 'Galaxy S9 Plus',
                'Galaxy S10e', 'Galaxy S10', 'Galaxy S10 Plus', 'Galaxy S10 Lite',
                'Galaxy S20', 'Galaxy S20 Plus', 'Galaxy S20 Ultra', 'Galaxy S20 FE',
                'Galaxy S21', 'Galaxy S21 Plus', 'Galaxy S21 Ultra', 'Galaxy S21 FE',
                'Galaxy S22', 'Galaxy S22 Plus', 'Galaxy S22 Ultra',
                'Galaxy S23', 'Galaxy S23 Plus', 'Galaxy S23 Ultra', 'Galaxy S23 FE',
                'Galaxy S24', 'Galaxy S24 Plus', 'Galaxy S24 Ultra',
                'Galaxy S25', 'Galaxy S25 Plus', 'Galaxy S25 Ultra',
            ],

            'Xiaomi' => [
                'Redmi 9', 'Redmi 9A', 'Redmi 9C',
                'Redmi 10', 'Redmi 10A', 'Redmi 10C',
                'Redmi 11 Prime',
                'Redmi 12', 'Redmi 12C',
                'Redmi 13', 'Redmi 13C',

                'Redmi Note 9', 'Redmi Note 9S', 'Redmi Note 9 Pro',
                'Redmi Note 10', 'Redmi Note 10S', 'Redmi Note 10 Pro',
                'Redmi Note 11', 'Redmi Note 11S', 'Redmi Note 11 Pro', 'Redmi Note 11 Pro Plus',
                'Redmi Note 12', 'Redmi Note 12 5G', 'Redmi Note 12 Pro', 'Redmi Note 12 Pro Plus',
                'Redmi Note 13 4G', 'Redmi Note 13 5G', 'Redmi Note 13 Pro 4G', 'Redmi Note 13 Pro 5G', 'Redmi Note 13 Pro Plus',
                'Redmi Note 14 4G', 'Redmi Note 14 5G', 'Redmi Note 14 Pro', 'Redmi Note 14 Pro Plus',

                'Xiaomi Mi 11 Lite', 'Xiaomi 11T', 'Xiaomi 11T Pro',
                'Xiaomi 12', 'Xiaomi 12T', 'Xiaomi 12T Pro',
                'Xiaomi 13', 'Xiaomi 13 Lite', 'Xiaomi 13T', 'Xiaomi 13T Pro',
                'Xiaomi 14', 'Xiaomi 14T', 'Xiaomi 14T Pro',

                'POCO X3 Pro', 'POCO X4 Pro', 'POCO X5', 'POCO X5 Pro', 'POCO X6', 'POCO X6 Pro',
                'POCO M4 Pro', 'POCO M5', 'POCO M6 Pro',
                'POCO F4', 'POCO F5', 'POCO F5 Pro', 'POCO F6', 'POCO F6 Pro',
            ],

            'Realme' => [
                'Realme 8', 'Realme 8 Pro',
                'Realme 9', 'Realme 9 Pro', 'Realme 9 Pro Plus',
                'Realme 10', 'Realme 10 Pro', 'Realme 10 Pro Plus',
                'Realme 11', 'Realme 11 Pro', 'Realme 11 Pro Plus',
                'Realme 12', 'Realme 12 Plus', 'Realme 12 Pro', 'Realme 12 Pro Plus',

                'Realme C21', 'Realme C25', 'Realme C31', 'Realme C33', 'Realme C35',
                'Realme C51', 'Realme C53', 'Realme C55', 'Realme C61', 'Realme C67',

                'Realme GT 2', 'Realme GT 2 Pro', 'Realme GT Neo 3', 'Realme GT 6',
                'Narzo 50', 'Narzo 60', 'Narzo 70',

                'RMX3085', 'RMX3201', 'RMX3478', 'RMX3661', 'RMX3710',
            ],

            'Huawei' => [
                'Huawei Y6p', 'Huawei Y7a', 'Huawei Y8p', 'Huawei Y9a',
                'Huawei Nova 7i', 'Huawei Nova 8i', 'Huawei Nova 9', 'Huawei Nova 10', 'Huawei Nova 10 SE', 'Huawei Nova 11i',
                'Huawei P30', 'Huawei P30 Pro', 'Huawei P40', 'Huawei P40 Pro',
                'Huawei P50', 'Huawei P50 Pro',
                'Huawei P60', 'Huawei P60 Pro',
                'Huawei Mate 40 Pro', 'Huawei Mate 50', 'Huawei Mate 50 Pro', 'Huawei Mate 60 Pro',
                'Huawei Pura 70', 'Huawei Pura 70 Pro',
            ],

            'Honor' => [
                'Honor 50', 'Honor 50 Lite',
                'Honor 70', 'Honor 90', 'Honor 90 Lite', 'Honor 100',
                'Honor 200', 'Honor 200 Pro',
                'Honor X5', 'Honor X6', 'Honor X6a', 'Honor X7', 'Honor X8', 'Honor X8a', 'Honor X9', 'Honor X9a', 'Honor X9b',
                'Honor Magic4 Pro', 'Honor Magic5 Lite', 'Honor Magic5 Pro', 'Honor Magic6 Pro',
            ],
        ];
    }
}