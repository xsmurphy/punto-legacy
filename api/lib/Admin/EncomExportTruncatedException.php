<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

/**
 * El listado que el legacy devolvió está CAPADO: hay más filas de las que
 * contestó y no se pudo pedir el resto (context/77 §17.13).
 *
 * ── Por qué es un tipo propio y no un error más ──────────────────────────
 * Los importadores del histórico atrapan por MES y por FILA para que un
 * problema puntual no tire el dominio entero: una venta con la fecha ilegible
 * se anota y la corrida sigue. Esa tolerancia es correcta para un dato malo y
 * es exactamente lo que NO sirve para un export incompleto — ahí seguir
 * significa asentar un mes al que le faltan filas y reportarlo como
 * `imported`, que es cómo la primera corrida real perdió ventas sin una sola
 * señal (3 meses clavados en 100 ventas, que es el techo y no el volumen).
 *
 * Con un tipo propio la regla se puede escribir una vez y leer de un vistazo:
 * los `catch` por mes y por fila lo RE-LANZAN, así que un export truncado
 * siempre termina abortando el dominio. Un `EncomMigrationException` pelado no
 * se distingue de "la sesión caducó" ni de "no se pudo leer la fecha", y
 * cualquiera de los catch existentes se lo habría tragado.
 *
 * ── Por qué NO extiende `EncomMigrationException` ────────────────────────
 * Porque esa clase es `final`, y des-finalizarla para colgarle esta sería
 * cambiar una clase compartida por comodidad de acá. No hace falta: lo único
 * que este tipo tiene que hacer es ser DISTINGUIBLE, y quien lo atrapa arriba
 * lo hace por `\Throwable` (el worker y el dispatcher de dominios), que lo
 * toma igual. El código HTTP viaja como `code` por el mismo motivo que en la
 * otra: para que el mensaje que ve el operador tenga con qué explicarse.
 */
final class EncomExportTruncatedException extends \RuntimeException
{
}
