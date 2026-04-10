<?php

return [
    'estimation' => [
        'preparation_retention_factor' => 0.30,
        'measurements' => [
            'grams_per_tablespoon' => 15,
            'grams_per_tablespoon_fat' => 14,
            'grams_per_teaspoon' => 5,
        ],
        'references' => [
            'arroz branco cozido' => [
                'aliases' => ['arroz', 'arroz branco'],
                'calories_per_100g' => 128,
                'default_grams' => 120,
            ],
            'feijão carioca cozido' => [
                'aliases' => ['feijao', 'feijão', 'feijao carioca', 'feijão carioca'],
                'calories_per_100g' => 77,
                'default_grams' => 100,
            ],
            'peito de frango grelhado' => [
                'aliases' => ['peito de frango', 'frango grelhado'],
                'calories_per_100g' => 165,
                'default_grams' => 120,
            ],
            'coxa/sobrecoxa de frango cozida' => [
                'aliases' => ['frango assado', 'frango sem pele', 'coxa de frango', 'sobrecoxa de frango'],
                'calories_per_100g' => 190,
                'default_grams' => 120,
            ],
            'carne bovina magra grelhada' => [
                'aliases' => ['carne bovina', 'carne magra', 'bife', 'carne'],
                'calories_per_100g' => 170,
                'default_grams' => 120,
            ],
            'ovo cozido' => [
                'aliases' => ['ovo', 'ovo cozido'],
                'default_grams' => 50,
                'default_calories' => 78,
            ],
            'farinha de mandioca' => [
                'aliases' => ['farinha', 'farinha de mandioca'],
                'calories_per_100g' => 360,
                'default_grams' => 20,
            ],
            'banana' => [
                'aliases' => ['banana'],
                'calories_per_100g' => 89,
                'default_grams' => 100,
            ],
            'batata doce cozida' => [
                'aliases' => ['batata doce', 'batata-doce'],
                'calories_per_100g' => 86,
                'default_grams' => 100,
            ],
            'macarrão cozido' => [
                'aliases' => ['macarrao', 'macarrão', 'massa'],
                'calories_per_100g' => 130,
                'default_grams' => 120,
            ],
            'pão francês' => [
                'aliases' => ['pao frances', 'pão francês'],
                'default_grams' => 50,
                'default_calories' => 150,
            ],
            'leite integral' => [
                'aliases' => ['leite', 'leite integral'],
                'calories_per_100g' => 60,
                'default_grams' => 200,
            ],
            'queijo mussarela' => [
                'aliases' => ['mussarela', 'muçarela', 'queijo mussarela'],
                'calories_per_100g' => 280,
                'default_grams' => 30,
            ],
            'manteiga' => [
                'aliases' => ['manteiga'],
                'calories_per_100g' => 717,
                'default_grams' => 10,
                'is_cooking_fat' => true,
            ],
            'azeite/óleo' => [
                'aliases' => ['azeite', 'oleo', 'óleo'],
                'calories_per_100g' => 884,
                'default_grams' => 5,
                'is_cooking_fat' => true,
            ],
        ],
    ],
];
