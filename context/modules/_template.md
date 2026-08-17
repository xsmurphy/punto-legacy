# NN — <Módulo>

> Estado del doc: <borrador | verificado contra código YYYY-MM-DD>
> Responsable de la última verificación: <sesión/fecha>

## 1. Qué resuelve

Una o dos frases. Qué problema del comercio cubre este módulo. Si alguien
pregunta "¿para qué existe?", esto responde.

## 2. Entidades y datos

Tablas propias, con las columnas que tienen SIGNIFICADO no obvio. No copiar el
schema entero — el schema está en `db-schema-postgres.sql`. Acá va lo que un
`\d tabla` NO te dice: qué significa cada valor, qué invariantes valen.

| Tabla | Qué guarda | Invariantes / trampas |
|---|---|---|

Incluir SIEMPRE los campos con nombres engañosos. Ejemplo real: en compras,
`transactionStatus` (1 vigente / 6 anulada) se mostraba como "Completa" y se
leía como "pagada", cuando el estado de pago es `transactionComplete` — dos
diagnósticos errados salieron de ahí.

## 3. Reglas de negocio

Numeradas, cada una con su evidencia `path:line`. Una regla sin evidencia es
una suposición, y las suposiciones son lo que este doc existe para eliminar.

1. **<Regla>** — `path:line`. <Qué pasa, y qué pasa en el borde.>

Marcar explícitamente las que son **decisión del owner** (con fecha) y las que
son consecuencia técnica.

## 4. Flujos principales

Los caminos que el usuario recorre de verdad. Para cada uno: qué dispara, qué
valida, qué persiste, qué imprime, qué avisa. Incluir los caminos de ERROR:
qué pasa si falla a la mitad.

## 5. Interacciones con otros módulos

**La sección más importante del doc.** Es la que evita las malas
integraciones.

| Módulo | Qué le pide / le da | Contrato (qué asume) |
|---|---|---|

Ser explícito sobre lo que este módulo **asume** de los otros. Un supuesto
escrito se puede refutar; uno implícito se descubre roto en producción.

## 6. Offline (solo módulos del POS)

Qué funciona sin conexión y qué no, según la regla base
(`context/08-convenciones-criticas.md §53`): lo que se EMITE va offline; lo que
requiere estado compartido entre cajas puede bloquear. Si el módulo no es del
POS, borrar esta sección.

## 7. Huecos conocidos y NO verificado

Lo que falta, lo que está a medias, y —crítico— **lo que no se pudo verificar
leyendo el código**. Marcarlo como no verificado es información valiosa;
afirmarlo sin evidencia es lo que rompe integraciones.

## 8. Planes y decisiones relacionados

Enlaces a los `context/NN-*.md` que planifican cambios sobre este módulo. Este
doc describe **cómo es y cómo debe funcionar**; los planes describen **qué se
va a cambiar**. No duplicar contenido: enlazar.
