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
            // ── Cereais e grãos ─────────────────────────────────────
            'arroz branco cozido' => [
                'aliases' => ['arroz', 'arroz branco'],
                'calories_per_100g' => 128,
                'default_grams' => 120,
            ],
            'arroz integral cozido' => [
                'aliases' => ['arroz integral'],
                'calories_per_100g' => 124,
                'default_grams' => 120,
            ],
            'aveia' => [
                'aliases' => ['aveia', 'aveia em flocos'],
                'calories_per_100g' => 394,
                'default_grams' => 30,
            ],
            'granola' => [
                'aliases' => ['granola'],
                'calories_per_100g' => 471,
                'default_grams' => 40,
            ],
            'tapioca' => [
                'aliases' => ['tapioca', 'goma de tapioca'],
                'calories_per_100g' => 360,
                'default_grams' => 30,
            ],
            'cuscuz de milho' => [
                'aliases' => ['cuscuz', 'cuscuz de milho'],
                'calories_per_100g' => 113,
                'default_grams' => 120,
            ],
            'milho cozido' => [
                'aliases' => ['milho', 'milho verde', 'espiga de milho'],
                'calories_per_100g' => 96,
                'default_grams' => 100,
            ],

            // ── Leguminosas ─────────────────────────────────────────
            'feijão carioca cozido' => [
                'aliases' => ['feijao', 'feijão', 'feijao carioca', 'feijão carioca'],
                'calories_per_100g' => 77,
                'default_grams' => 100,
            ],
            'feijão preto cozido' => [
                'aliases' => ['feijao preto', 'feijão preto'],
                'calories_per_100g' => 77,
                'default_grams' => 100,
            ],
            'lentilha cozida' => [
                'aliases' => ['lentilha'],
                'calories_per_100g' => 93,
                'default_grams' => 100,
            ],
            'grão-de-bico cozido' => [
                'aliases' => ['grao de bico', 'grão de bico', 'grao-de-bico', 'grão-de-bico'],
                'calories_per_100g' => 164,
                'default_grams' => 100,
            ],

            // ── Carnes e ovos ───────────────────────────────────────
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
            'carne de porco assada' => [
                'aliases' => ['carne de porco', 'porco', 'lombo', 'bisteca'],
                'calories_per_100g' => 211,
                'default_grams' => 120,
            ],
            'linguiça calabresa' => [
                'aliases' => ['linguica', 'linguiça', 'calabresa'],
                'calories_per_100g' => 296,
                'default_grams' => 60,
            ],
            'ovo cozido' => [
                'aliases' => ['ovo', 'ovo cozido'],
                'default_grams' => 50,
                'default_calories' => 78,
            ],
            'ovo frito' => [
                'aliases' => ['ovo frito'],
                'default_grams' => 60,
                'default_calories' => 107,
            ],

            // ── Peixes e frutos do mar ──────────────────────────────
            'peixe grelhado' => [
                'aliases' => ['peixe', 'peixe grelhado', 'tilapia', 'tilápia', 'filé de peixe'],
                'calories_per_100g' => 96,
                'default_grams' => 120,
            ],
            'atum enlatado' => [
                'aliases' => ['atum', 'atum em lata'],
                'calories_per_100g' => 116,
                'default_grams' => 60,
            ],
            'sardinha enlatada' => [
                'aliases' => ['sardinha', 'sardinha em lata'],
                'calories_per_100g' => 208,
                'default_grams' => 60,
            ],
            'camarão cozido' => [
                'aliases' => ['camarao', 'camarão'],
                'calories_per_100g' => 99,
                'default_grams' => 100,
            ],

            // ── Tubérculos e raízes ─────────────────────────────────
            'batata inglesa cozida' => [
                'aliases' => ['batata', 'batata inglesa', 'batata cozida'],
                'calories_per_100g' => 52,
                'default_grams' => 120,
            ],
            'batata doce cozida' => [
                'aliases' => ['batata doce', 'batata-doce'],
                'calories_per_100g' => 86,
                'default_grams' => 100,
            ],
            'mandioca cozida' => [
                'aliases' => ['mandioca', 'aipim', 'macaxeira'],
                'calories_per_100g' => 125,
                'default_grams' => 100,
            ],
            'inhame cozido' => [
                'aliases' => ['inhame', 'cará'],
                'calories_per_100g' => 97,
                'default_grams' => 100,
            ],

            // ── Massas e pães ───────────────────────────────────────
            'macarrão cozido' => [
                'aliases' => ['macarrao', 'macarrão', 'massa', 'espaguete'],
                'calories_per_100g' => 130,
                'default_grams' => 120,
            ],
            'pão francês' => [
                'aliases' => ['pao frances', 'pão francês', 'pão'],
                'default_grams' => 50,
                'default_calories' => 150,
            ],
            'pão de forma' => [
                'aliases' => ['pao de forma', 'pão de forma'],
                'default_grams' => 25,
                'default_calories' => 62,
            ],
            'pão de queijo' => [
                'aliases' => ['pao de queijo', 'pão de queijo'],
                'default_grams' => 40,
                'default_calories' => 113,
            ],
            'torrada' => [
                'aliases' => ['torrada', 'torrada integral'],
                'default_grams' => 15,
                'default_calories' => 56,
            ],

            // ── Farinhas e farofas ──────────────────────────────────
            'farinha de mandioca' => [
                'aliases' => ['farinha', 'farinha de mandioca', 'farofa'],
                'calories_per_100g' => 360,
                'default_grams' => 20,
            ],

            // ── Verduras e legumes ──────────────────────────────────
            'alface' => [
                'aliases' => ['alface', 'salada verde'],
                'calories_per_100g' => 14,
                'default_grams' => 50,
            ],
            'tomate' => [
                'aliases' => ['tomate'],
                'calories_per_100g' => 18,
                'default_grams' => 80,
            ],
            'cenoura cozida' => [
                'aliases' => ['cenoura'],
                'calories_per_100g' => 30,
                'default_grams' => 60,
            ],
            'brócolis cozido' => [
                'aliases' => ['brocolis', 'brócolis'],
                'calories_per_100g' => 35,
                'default_grams' => 80,
            ],
            'abobrinha refogada' => [
                'aliases' => ['abobrinha'],
                'calories_per_100g' => 15,
                'default_grams' => 80,
            ],
            'chuchu cozido' => [
                'aliases' => ['chuchu'],
                'calories_per_100g' => 17,
                'default_grams' => 80,
            ],
            'abóbora cozida' => [
                'aliases' => ['abobora', 'abóbora'],
                'calories_per_100g' => 28,
                'default_grams' => 100,
            ],
            'couve refogada' => [
                'aliases' => ['couve'],
                'calories_per_100g' => 29,
                'default_grams' => 60,
            ],
            'quiabo cozido' => [
                'aliases' => ['quiabo'],
                'calories_per_100g' => 22,
                'default_grams' => 80,
            ],
            'pepino' => [
                'aliases' => ['pepino'],
                'calories_per_100g' => 12,
                'default_grams' => 80,
            ],
            'beterraba cozida' => [
                'aliases' => ['beterraba'],
                'calories_per_100g' => 49,
                'default_grams' => 60,
            ],

            // ── Frutas ──────────────────────────────────────────────
            'banana' => [
                'aliases' => ['banana'],
                'calories_per_100g' => 89,
                'default_grams' => 100,
            ],
            'maçã' => [
                'aliases' => ['maca', 'maçã'],
                'calories_per_100g' => 52,
                'default_grams' => 150,
            ],
            'laranja' => [
                'aliases' => ['laranja'],
                'calories_per_100g' => 37,
                'default_grams' => 150,
            ],
            'mamão' => [
                'aliases' => ['mamao', 'mamão'],
                'calories_per_100g' => 40,
                'default_grams' => 150,
            ],
            'manga' => [
                'aliases' => ['manga'],
                'calories_per_100g' => 60,
                'default_grams' => 150,
            ],
            'melancia' => [
                'aliases' => ['melancia'],
                'calories_per_100g' => 30,
                'default_grams' => 200,
            ],
            'abacaxi' => [
                'aliases' => ['abacaxi'],
                'calories_per_100g' => 50,
                'default_grams' => 100,
            ],
            'morango' => [
                'aliases' => ['morango'],
                'calories_per_100g' => 33,
                'default_grams' => 100,
            ],
            'uva' => [
                'aliases' => ['uva'],
                'calories_per_100g' => 67,
                'default_grams' => 100,
            ],
            'abacate' => [
                'aliases' => ['abacate'],
                'calories_per_100g' => 160,
                'default_grams' => 80,
            ],
            'açaí' => [
                'aliases' => ['acai', 'açaí'],
                'calories_per_100g' => 58,
                'default_grams' => 200,
            ],

            // ── Laticínios ──────────────────────────────────────────
            'leite integral' => [
                'aliases' => ['leite', 'leite integral'],
                'calories_per_100g' => 60,
                'default_grams' => 200,
            ],
            'leite desnatado' => [
                'aliases' => ['leite desnatado', 'leite zero'],
                'calories_per_100g' => 35,
                'default_grams' => 200,
            ],
            'iogurte natural' => [
                'aliases' => ['iogurte', 'iogurte natural'],
                'calories_per_100g' => 51,
                'default_grams' => 170,
            ],
            'iogurte grego' => [
                'aliases' => ['iogurte grego'],
                'calories_per_100g' => 90,
                'default_grams' => 120,
            ],
            'queijo mussarela' => [
                'aliases' => ['mussarela', 'muçarela', 'queijo mussarela'],
                'calories_per_100g' => 280,
                'default_grams' => 30,
            ],
            'queijo minas frescal' => [
                'aliases' => ['queijo minas', 'queijo branco', 'queijo frescal'],
                'calories_per_100g' => 240,
                'default_grams' => 30,
            ],
            'requeijão' => [
                'aliases' => ['requeijao', 'requeijão'],
                'calories_per_100g' => 257,
                'default_grams' => 20,
            ],
            'cream cheese' => [
                'aliases' => ['cream cheese'],
                'calories_per_100g' => 342,
                'default_grams' => 20,
            ],

            // ── Gorduras e óleos ────────────────────────────────────
            'manteiga' => [
                'aliases' => ['manteiga'],
                'calories_per_100g' => 717,
                'default_grams' => 10,
                'is_cooking_fat' => true,
            ],
            'óleo' => [
                'aliases' => ['oleo', 'óleo', 'oleo de soja', 'óleo de soja'],
                'calories_per_100g' => 884,
                'default_grams' => 5,
                'is_cooking_fat' => true,
            ],
            'margarina' => [
                'aliases' => ['margarina'],
                'calories_per_100g' => 720,
                'default_grams' => 10,
                'is_cooking_fat' => true,
            ],

            // ── Bebidas ─────────────────────────────────────────────
            'café com açúcar' => [
                'aliases' => ['cafe', 'café', 'cafezinho'],
                'default_grams' => 50,
                'default_calories' => 30,
            ],
            'suco de laranja natural' => [
                'aliases' => ['suco de laranja', 'suco natural'],
                'calories_per_100g' => 45,
                'default_grams' => 250,
            ],
            'refrigerante' => [
                'aliases' => ['refrigerante', 'coca', 'coca-cola', 'guarana', 'guaraná'],
                'calories_per_100g' => 42,
                'default_grams' => 350,
            ],
            'cerveja' => [
                'aliases' => ['cerveja'],
                'calories_per_100g' => 43,
                'default_grams' => 350,
            ],

            // ── Embutidos e processados ─────────────────────────────
            'presunto' => [
                'aliases' => ['presunto'],
                'calories_per_100g' => 110,
                'default_grams' => 30,
            ],
            'peito de peru' => [
                'aliases' => ['peito de peru'],
                'calories_per_100g' => 95,
                'default_grams' => 30,
            ],
            'salsicha' => [
                'aliases' => ['salsicha'],
                'calories_per_100g' => 257,
                'default_grams' => 50,
            ],

            // ── Salgados e lanches ──────────────────────────────────
            'coxinha' => [
                'aliases' => ['coxinha'],
                'default_grams' => 80,
                'default_calories' => 215,
            ],
            'pastel' => [
                'aliases' => ['pastel'],
                'default_grams' => 100,
                'default_calories' => 280,
            ],
            'pão de mel' => [
                'aliases' => ['pao de mel', 'pão de mel'],
                'default_grams' => 40,
                'default_calories' => 148,
            ],
            'esfirra' => [
                'aliases' => ['esfirra', 'esfiha'],
                'default_grams' => 80,
                'default_calories' => 184,
            ],
            'empada' => [
                'aliases' => ['empada', 'empadinha'],
                'default_grams' => 60,
                'default_calories' => 175,
            ],

            // ── Doces e sobremesas ──────────────────────────────────
            'brigadeiro' => [
                'aliases' => ['brigadeiro'],
                'default_grams' => 25,
                'default_calories' => 90,
            ],
            'pudim' => [
                'aliases' => ['pudim'],
                'default_grams' => 100,
                'default_calories' => 180,
            ],
            'sorvete' => [
                'aliases' => ['sorvete'],
                'calories_per_100g' => 207,
                'default_grams' => 80,
            ],
            'chocolate' => [
                'aliases' => ['chocolate', 'chocolate ao leite'],
                'calories_per_100g' => 535,
                'default_grams' => 25,
            ],
            'bolo simples' => [
                'aliases' => ['bolo'],
                'calories_per_100g' => 310,
                'default_grams' => 70,
            ],
            'biscoito/bolacha' => [
                'aliases' => ['biscoito', 'bolacha', 'biscoito cream cracker', 'bolacha cream cracker'],
                'calories_per_100g' => 443,
                'default_grams' => 30,
            ],

            // ── Oleaginosas ─────────────────────────────────────────
            'castanha-do-pará' => [
                'aliases' => ['castanha do para', 'castanha-do-pará', 'castanha do pará'],
                'calories_per_100g' => 656,
                'default_grams' => 10,
            ],
            'amendoim' => [
                'aliases' => ['amendoim', 'amendoim torrado'],
                'calories_per_100g' => 567,
                'default_grams' => 30,
            ],
            'pasta de amendoim' => [
                'aliases' => ['pasta de amendoim'],
                'calories_per_100g' => 588,
                'default_grams' => 20,
            ],

            // ── Condimentos e complementos ──────────────────────────
            'açúcar' => [
                'aliases' => ['acucar', 'açúcar'],
                'calories_per_100g' => 387,
                'default_grams' => 10,
            ],
            'mel' => [
                'aliases' => ['mel'],
                'calories_per_100g' => 304,
                'default_grams' => 15,
            ],
            'maionese' => [
                'aliases' => ['maionese'],
                'calories_per_100g' => 680,
                'default_grams' => 15,
            ],
            'ketchup' => [
                'aliases' => ['ketchup', 'catchup'],
                'calories_per_100g' => 112,
                'default_grams' => 15,
            ],
            'mostarda' => [
                'aliases' => ['mostarda'],
                'calories_per_100g' => 60,
                'default_grams' => 10,
            ],

            // ── Proteínas vegetais e suplementos ────────────────────
            'whey protein' => [
                'aliases' => ['whey', 'whey protein', 'shake de proteina'],
                'default_grams' => 30,
                'default_calories' => 120,
            ],
        ],
    ],
];
