<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * La adopción de la serie SIFEN (`dSerieNum`, mig 223) de un documento numerado
 * sin serie quedó bloqueada por una causa que NO se resuelve sola: el número ya
 * existe en la serie a adoptar, el período de la venta está cerrado, o la serie
 * configurada en la caja no es emitible.
 *
 * Es una clase propia y no un `RuntimeException` genérico porque el caller
 * (`EInvoiceService::issueClaimedDocument()`) la trata distinto: estaciona el
 * documento en `error` con los intentos automáticos agotados en vez de dejar que
 * el drainer lo repita cada pocos minutos. El mensaje es para el comercio.
 */
final class SerieAdoptionBlockedException extends \RuntimeException
{
}
