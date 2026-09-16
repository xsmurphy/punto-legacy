---
title: Control de stock y ajustes
slug: control-de-stock-y-ajustes
modulo: stock
audiencia: administrativo
keywords: [stock, inventario, ajuste de stock, merma, conteo de stock, conteo fisico, faltante, sobrante, corregir stock, saldo de stock]
resumen: Cómo se calcula el saldo de stock de un artículo y cómo corregirlo con un ajuste o un conteo físico.
---

# Control de stock y ajustes

Punto lleva el saldo de stock de cada artículo sumando todos los movimientos que le ocurrieron: ventas, compras, transferencias, mermas y ajustes. Este artículo explica cómo consultar ese saldo y cómo corregirlo cuando la cantidad real en el depósito no coincide con lo que el sistema muestra.

## Cómo se mueve el stock

Cada vez que un artículo entra o sale de un depósito o sucursal (por una venta, una compra, una transferencia, etc.) queda registrado un movimiento. El saldo que ves en el catálogo es la suma de todos esos movimientos para ese artículo en esa sucursal. Por eso el stock siempre queda trazable: podés reconstruir cómo se llegó a un número mirando el historial de movimientos, no solo el resultado final.

Los servicios y los artículos marcados como "sin control de stock" no generan movimientos — no hace falta ajustarles nada porque no llevan saldo.

## Cuándo hacer un ajuste de stock

Usá un ajuste de stock cuando detectás una diferencia puntual entre lo que el sistema dice y lo que hay físicamente: un producto roto, vencido, robado, o un error de carga anterior. El ajuste registra el motivo y corrige el saldo en el momento.

1. Entrá a Artículos › Ajustes de stock.
2. Elegí la sucursal o depósito donde vas a ajustar.
3. Buscá el artículo y cargá la cantidad correcta o la diferencia (según cómo esté armada la pantalla: cantidad final o cantidad a sumar/restar).
4. Confirmá el ajuste. El movimiento queda registrado y el saldo se actualiza al instante.

## Cuándo hacer un conteo de stock

El conteo de stock (también llamado toma de inventario) sirve para revisar TODO el catálogo de una sucursal de una sola vez, en vez de corregir artículo por artículo. Es el mecanismo recomendado para un relevo de turno o un cierre de período.

1. Entrá a Artículos › Inventario.
2. Iniciá un conteo nuevo para la sucursal (o el depósito) que vas a contar.
3. El sistema arma una lista de los artículos que tienen presencia en esa sucursal (los que ya tuvieron stock ahí alguna vez). Si es la primera vez que contás esa sucursal y querés incluir artículos sin stock previo, hay una opción para incluirlos igual.
4. Recorré la lista y cargá la cantidad real que contaste de cada artículo.
5. Al finalizar el conteo, el sistema genera automáticamente un ajuste por cada artículo donde la cantidad contada difiere de la que tenía registrada. Los artículos que coinciden no generan ningún movimiento.
6. Si cancelás un conteo antes de finalizarlo, no se aplica ningún cambio de stock.

## Una nota sobre el costo de inventario

El costo que se registra para cada artículo al ingresar stock (por ejemplo al cargar una compra) es el costo real que pagaste, con impuestos incluidos — no un costo neto. Esto es una decisión de cómo Punto valora tu inventario, y no requiere que hagas nada distinto al cargar tus compras.

## Preguntas frecuentes

**¿Un ajuste de stock afecta algún reporte de ventas?**
No. El ajuste solo corrige el saldo de inventario, no genera una venta ni afecta la caja.

**¿Puedo deshacer un conteo ya finalizado?**
No directamente. Si te equivocaste al cargar una cantidad en un conteo ya finalizado, corregilo con un ajuste de stock puntual sobre ese artículo.

**¿Quién puede hacer ajustes y conteos?**
Depende del permiso que tenga asignado cada usuario en su rol. Si no ves la opción disponible, consultá con quien administra los usuarios de tu cuenta.
