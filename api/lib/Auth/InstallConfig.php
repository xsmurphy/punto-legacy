<?php
/**
 * Catálogo de items y módulos pre-cargados durante el signup,
 * en base a la categoría/rubro de la empresa.
 *
 * Port FIEL de `$installConfig` (panel/includes/config.php:303-660).
 * Single source of truth para /api — el legacy mantiene su propia copia
 * hasta que /panel desaparezca. Cualquier cambio (rubro nuevo, item demo)
 * debe replicarse en ambos lados hasta entonces.
 */

declare(strict_types=1);

namespace Punto\Api\Auth;

final class InstallConfig
{
    /**
     * @return list<array{match: list<string>, items: list<array{name:string, price:string}>, modules: list<string>}>
     */
    public static function all(): array
    {
        return self::$config;
    }

    /** @var list<array{match: list<string>, items: list<array{name:string, price:string}>, modules: list<string>}> */
    private static array $config = [
        [
            'match' => ['0.1','0.2','0','7','7.1','7.2','7.3','7.4','7.5'],
            'items' => [
                ['name' => 'Cuota mensual', 'price' => '12000'],
                ['name' => 'Clase personalizada', 'price' => '32000'],
                ['name' => 'Clase grupal', 'price' => '22000'],
            ],
            'modules' => ['schedule','dunning'],
        ],
        [
            'match' => ['0.3','0.4','0.5','0.6'],
            'items' => [
                ['name' => 'Consulta General', 'price' => '12000'],
                ['name' => 'Consulta personalizada', 'price' => '32000'],
                ['name' => 'Tratamiento', 'price' => '22000'],
            ],
            'modules' => ['schedule','dunning'],
        ],
        [
            'match' => ['1.1','1.3','0.5','0.6'],
            'items' => [
                ['name' => 'Pan Francés', 'price' => '12000'],
                ['name' => 'Café Espresso', 'price' => '32000'],
                ['name' => 'Sandwich Gourmet', 'price' => '22000'],
            ],
            'modules' => ['tables', 'ordersPanel', 'production', 'kds','feedback'],
        ],
        [
            'match' => ['1.2','1.7','1.9','1.8','1.4','1.6','1'],
            'items' => [
                ['name' => 'Plato Principal', 'price' => '12000'],
                ['name' => 'Menú a la carta', 'price' => '32000'],
                ['name' => 'Tragos', 'price' => '10000'],
                ['name' => 'Vino en copa', 'price' => '2000'],
                ['name' => 'Botella de Vino', 'price' => '50000'],
            ],
            'modules' => ['tables', 'ordersPanel', 'production', 'kds','feedback'],
        ],
        [
            'match' => ['1.10','1.11'],
            'items' => [
                ['name' => 'Batido de frutas', 'price' => '23000'],
                ['name' => 'Helado de Almendras', 'price' => '32000'],
                ['name' => 'Jugo de Naranja', 'price' => '5000'],
            ],
            'modules' => ['tables', 'ordersPanel', 'production', 'kds','feedback'],
        ],
        [
            'match' => ['2.1','2.2','2.5','2.8','2'],
            'items' => [
                ['name' => 'Cuadro Vintage', 'price' => '23000'],
                ['name' => 'Libros de Autosuperación', 'price' => '32000'],
                ['name' => 'Disco de Jazz Clásico', 'price' => '5000'],
                ['name' => 'Mueble de Roble', 'price' => '60000'],
                ['name' => 'Reloj de pulcera', 'price' => '43000'],
                ['name' => 'Anillo de oro', 'price' => '171000'],
            ],
            'modules' => ['ecom','feedback'],
        ],
        [
            'match' => ['2.4'],
            'items' => [
                ['name' => 'Tablet 10"', 'price' => '23000'],
                ['name' => 'Smart Watch X3', 'price' => '32000'],
                ['name' => 'Laptop A12', 'price' => '225000'],
            ],
            'modules' => ['ecom','feedback','dunning'],
        ],
        [
            'match' => ['2.7'],
            'items' => [
                ['name' => 'Llave Francesa', 'price' => '23000'],
                ['name' => 'Taladro Eléctrico', 'price' => '32000'],
                ['name' => 'Removedor de Pintura', 'price' => '2000'],
            ],
            'modules' => ['ecom','feedback'],
        ],
        [
            'match' => ['2.6'],
            'items' => [
                ['name' => 'Limpiador de Vidrios', 'price' => '23000'],
                ['name' => 'Tomates Secos', 'price' => '32000'],
                ['name' => 'Aceite de Oliva', 'price' => '25000'],
            ],
            'modules' => ['ecom','feedback'],
        ],
        [
            'match' => ['2.2','2.9','2.10','2.12'],
            'items' => [
                ['name' => 'Prenda M', 'price' => '23000'],
                ['name' => 'Pantalones S', 'price' => '32000'],
                ['name' => 'Calzados talle 8', 'price' => '25000'],
                ['name' => 'Collar', 'price' => '15000'],
                ['name' => 'Abrigo', 'price' => '80000'],
            ],
            'modules' => ['ecom','feedback'],
        ],
        [
            'match' => ['3','3.1','3.2','3.3','3.4','3.5','6','6.1','6.2','6.3','6.4','6.5'],
            'items' => [
                ['name' => 'Servicio por hora', 'price' => '23000'],
                ['name' => 'Servicio a Domicilio', 'price' => '32000'],
                ['name' => 'Servicio Express', 'price' => '25000'],
            ],
            'modules' => ['schedule','dunning','feedback'],
        ],
        [
            'match' => ['4','4.1','4.2','4.3','4.4','4.5'],
            'items' => [
                ['name' => 'Tarifa Regular', 'price' => '23000'],
                ['name' => 'Tarifa Nocturna', 'price' => '32000'],
                ['name' => 'Tarifa Express', 'price' => '25000'],
            ],
            'modules' => ['schedule','feedback'],
        ],
        [
            'match' => ['5','5.1','5.3','5.4','5.5','5.6'],
            'items' => [
                ['name' => 'Masaje', 'price' => '23000'],
                ['name' => 'Tratamiento', 'price' => '32000'],
                ['name' => 'Bronzeado completo', 'price' => '19000'],
                ['name' => 'Uñas postizas', 'price' => '25000'],
                ['name' => 'Limpieza profunda', 'price' => '67000'],
            ],
            'modules' => ['schedule','feedback'],
        ],
        [
            'match' => ['5.2'],
            'items' => [
                ['name' => 'Corte Simple', 'price' => '23000'],
                ['name' => 'Corte de Barba', 'price' => '32000'],
                ['name' => 'Lavado', 'price' => '25000'],
            ],
            'modules' => ['schedule','table','feedback'],
        ],
        [
            'match' => ['5.7'],
            'items' => [
                ['name' => 'Sesión de Tatuajes', 'price' => '23000'],
                ['name' => 'Tatuaje Personalizado', 'price' => '32000'],
                ['name' => 'Retoques', 'price' => '25000'],
            ],
            'modules' => ['schedule','feedback'],
        ],
        [
            'match' => ['8','8.1','8.2','8.3','8.4'],
            'items' => [
                ['name' => 'Plan Inicial', 'price' => '15000'],
                ['name' => 'Plan Standar', 'price' => '25000'],
                ['name' => 'Plan Pro', 'price' => '55000'],
            ],
            'modules' => ['schedule','dunning','recurring'],
        ],
    ];
}
