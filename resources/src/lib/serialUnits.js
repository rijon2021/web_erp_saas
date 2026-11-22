/**
 * How many serials a document line needs: one per base unit it moves —
 * quantity × pack multiplier × the line unit's factor (a "box of 10" sale unit
 * is 10 base units), mirroring SerialNumberService::serialUnits on the server.
 *
 * Unit factors come from GET unit_factors ({unit id: base units per unit}),
 * loaded once into a reactive map so computed counts update when it arrives.
 * Lines without a unit (adjustments, damages) are already in base units.
 */
import { reactive } from 'vue';
import http from './http';

const factors = reactive({});
let loading = null;

export function loadUnitFactors() {
    if (!loading) {
        loading = http.get('unit_factors')
            .then(data => { Object.assign(factors, data?.factors || {}); })
            .catch(() => { loading = null; });
    }
    return loading;
}

export function unitFactor(unitId) {
    const f = Number(factors[unitId]);
    return f > 0 ? f : 1;
}

export function serialUnits(quantity, unitId = null, packMultiplier = 1) {
    const pack = Number(packMultiplier) > 0 ? Number(packMultiplier) : 1;
    return Math.round((Number(quantity) || 0) * pack * (unitId ? unitFactor(unitId) : 1));
}

/** Serials a line needs: sale lines use sale_unit_id + pack, purchase / transfer lines purchase_unit_id. */
export function lineSerialUnits(line) {
    if (!line) return 0;
    return serialUnits(line.quantity, line.sale_unit_id || line.purchase_unit_id || null, line.pack_multiplier);
}
