/**
 * Categorías de empresa (rubros) — port directo de
 * panel/libraries/company_categories.php (single source of truth del legacy).
 *
 * Estructura: 8 grupos → ~70 rubros con código tipo "1.7" (Restaurante).
 * Cualquier rubro nuevo se agrega ACÁ y allí simultáneamente hasta que el
 * legacy desaparezca; el código numérico es el contract con la DB
 * (`settingCompanyCategoryId`).
 */
export interface CategoryItem {
  label: string
  value: string
}

export interface CategoryGroup {
  group: string
  items: CategoryItem[]
}

export const COMPANY_CATEGORIES: CategoryGroup[] = [
  {
    group: "Salud y Fitness",
    items: [
      { label: "Gimnasio/Club de Bienestar", value: "0.1" },
      { label: "Entrenador Personal", value: "0.2" },
      { label: "Medicina Alternativa", value: "0.3" },
      { label: "Medicina", value: "0.4" },
      { label: "Profesional de la Salud", value: "0.5" },
      { label: "Hospital/Centro de Salud", value: "0.6" },
      { label: "Otro", value: "0" },
    ],
  },
  {
    group: "Alimentos y Bebidas",
    items: [
      { label: "Panadería/Pastelería", value: "1.1" },
      { label: "Bar/Club", value: "1.2" },
      { label: "Cafetería", value: "1.3" },
      { label: "Food Truck", value: "1.4" },
      { label: "Comida Rápida", value: "1.6" },
      { label: "Restaurante", value: "1.7" },
      { label: "Comida Saludable", value: "1.8" },
      { label: "Vinos y Bebidas", value: "1.9" },
      { label: "Jugos y Smoothies", value: "1.10" },
      { label: "Heladería", value: "1.11" },
      { label: "Otro", value: "1" },
    ],
  },
  {
    group: "Retail",
    items: [
      { label: "Arte/Fotografía/Filmaciones", value: "2.1" },
      { label: "Libros/Música/Videos", value: "2.2" },
      { label: "Ropa/Accesorios", value: "2.3" },
      { label: "Electrónicos/Tecnología/Informática", value: "2.4" },
      { label: "Regalos", value: "2.5" },
      { label: "Kiosco/Mercado", value: "2.6" },
      { label: "Ferretería", value: "2.7" },
      { label: "Joyas/Relojes", value: "2.8" },
      { label: "Tienda de Mascotas", value: "2.9" },
      { label: "Tienda deportiva", value: "2.10" },
      { label: "Hogar/Decoración", value: "2.11" },
      { label: "Niños/Bebés", value: "2.12" },
      { label: "Otro", value: "2" },
    ],
  },
  {
    group: "Reparación",
    items: [
      { label: "Servicios para automóviles", value: "3.1" },
      { label: "Ropas/Reparación de calzados/Lavandería", value: "3.3" },
      { label: "Computadoras/Electrónica", value: "3.4" },
      { label: "Hogar Servicios", value: "3.5" },
      { label: "Otro", value: "3" },
    ],
  },
  {
    group: "Transporte",
    items: [
      { label: "Delivery", value: "4.1" },
      { label: "Limousine", value: "4.2" },
      { label: "Taxi", value: "4.3" },
      { label: "Bus", value: "4.4" },
      { label: "Movilización", value: "4.5" },
      { label: "Other", value: "4" },
    ],
  },
  {
    group: "Belleza",
    items: [
      { label: "Salón de Belleza", value: "5.1" },
      { label: "Peluquería/Barbería", value: "5.2" },
      { label: "Masajes", value: "5.3" },
      { label: "Spa de Uñas", value: "5.4" },
      { label: "Spa", value: "5.5" },
      { label: "Salon de bronceado", value: "5.6" },
      { label: "Tatuajes/Piercing", value: "5.7" },
      { label: "Otro", value: "5" },
    ],
  },
  {
    group: "Servicios Profesionales",
    items: [
      { label: "Contabilidad", value: "6.1" },
      { label: "Consultoría", value: "6.2" },
      { label: "Diseño", value: "6.3" },
      { label: "Marketing", value: "6.4" },
      { label: "Real State", value: "6.5" },
      { label: "Otro", value: "6" },
    ],
  },
  {
    group: "Educación",
    items: [
      { label: "Instituto", value: "7.1" },
      { label: "Universidad", value: "7.2" },
      { label: "Cursos y Capacitaciones", value: "7.3" },
      { label: "Enseñanza On-line", value: "7.4" },
      { label: "Idiomas", value: "7.5" },
      { label: "Otro", value: "7" },
    ],
  },
  {
    group: "Software",
    items: [
      { label: "App", value: "8.1" },
      { label: "SaaS", value: "8.2" },
      { label: "Online Service", value: "8.3" },
      { label: "Ecommerce", value: "8.4" },
      { label: "Otro", value: "8" },
    ],
  },
]
