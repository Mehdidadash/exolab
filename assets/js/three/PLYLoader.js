(function (root) {
    function parseHeader(text) {
        var lines = text.split(/\r?\n/);
        var header = { format: 'ascii', elements: [] };
        var currentElement = null;
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i].trim();
            if (!line) continue;
            var parts = line.split(/\s+/);
            var key = parts[0].toLowerCase();
            if (key === 'format') {
                header.format = parts[1];
            } else if (key === 'element') {
                if (currentElement) header.elements.push(currentElement);
                currentElement = { name: parts[1], count: parseInt(parts[2], 10), properties: [] };
            } else if (key === 'property' && currentElement) {
                // property list uchar int vertex_indices
                if (parts[1] === 'list') {
                    currentElement.properties.push({ type: 'list', countType: parts[2], itemType: parts[3], name: parts[4] });
                } else {
                    currentElement.properties.push({ type: parts[1], name: parts[2] });
                }
            } else if (key === 'end_header') {
                if (currentElement) header.elements.push(currentElement);
                break;
            }
        }
        return header;
    }

    function parseAscii(text) {
        var header = parseHeader(text);
        var after = text.indexOf('end_header');
        if (after === -1) return null;
        var body = text.slice(after + 10).trim();
        var lines = body.split(/\r?\n/).filter(Boolean);
        var idx = 0;
        var vertices = [];
        var faces = [];
        for (var e = 0; e < header.elements.length; e++) {
            var el = header.elements[e];
            if (el.name === 'vertex') {
                for (var i = 0; i < el.count && idx < lines.length; i++, idx++) {
                    var vals = lines[idx].trim().split(/\s+/).map(Number);
                    vertices.push([vals[0], vals[1], vals[2]]);
                }
            } else if (el.name === 'face') {
                for (var j = 0; j < el.count && idx < lines.length; j++, idx++) {
                    var parts = lines[idx].trim().split(/\s+/).map(Number);
                    var n = parts[0];
                    if (n >= 3) {
                        faces.push([parts[1], parts[2], parts[3]]);
                    }
                }
            } else {
                // skip unknown elements
                idx += el.count;
            }
        }

        if (!vertices.length) return null;
        var flat = [];
        for (var f = 0; f < faces.length; f++) {
            var face = faces[f];
            for (var k = 0; k < face.length; k++) {
                var v = vertices[face[k]];
                if (v) flat.push(v[0], v[1], v[2]);
            }
        }
        if (!flat.length) {
            // maybe vertices are already ordered as triangles
            for (var vi = 0; vi < vertices.length; vi++) {
                var vv = vertices[vi];
                flat.push(vv[0], vv[1], vv[2]);
            }
        }
        try { if (typeof console !== 'undefined') console.log('PLY parseAscii sample positions', flat.slice(0,12)); } catch(e){}
        var geometry = new root.THREE.BufferGeometry();
        geometry.setAttribute('position', new root.THREE.Float32BufferAttribute(flat, 3));
        geometry.computeVertexNormals();
        return geometry;
    }

    function parseBinary(buffer) {
        var data = new Uint8Array(buffer);
        // find 'end_header' sequence in bytes
        var pattern = 'end_header';
        var headerEnd = -1;
        for (var p = 0; p < data.length - pattern.length; p++) {
            var ok = true;
            for (var q = 0; q < pattern.length; q++) {
                if (data[p + q] !== pattern.charCodeAt(q)) { ok = false; break; }
            }
            if (ok) {
                // find newline after pattern
                var k = p + pattern.length;
                if (data[k] === 13 && data[k + 1] === 10) headerEnd = k + 2;
                else if (data[k] === 10) headerEnd = k + 1;
                else headerEnd = k;
                break;
            }
        }
        if (headerEnd === -1) return null;
        var headerText = new TextDecoder('utf-8').decode(data.slice(0, headerEnd));
        var header = parseHeader(headerText);
        var little = (header.format || '').indexOf('little') !== -1;
        var dv = new DataView(buffer, headerEnd);
        var offset = 0;
        var vertices = [];
        var faces = [];
        for (var e = 0; e < header.elements.length; e++) {
            var el = header.elements[e];
            if (el.name === 'vertex') {
                for (var vi = 0; vi < el.count; vi++) {
                    var x = dv.getFloat32(offset, little); offset += 4;
                    var y = dv.getFloat32(offset, little); offset += 4;
                    var z = dv.getFloat32(offset, little); offset += 4;
                    // skip any extra properties per vertex
                    // if more properties exist, try to skip them based on type count
                    // naive: if property count > 3, skip remaining as float32
                    if (el.properties.length > 3) {
                        var extra = el.properties.length - 3;
                        offset += 4 * extra;
                    }
                    vertices.push([x, y, z]);
                }
            } else if (el.name === 'face') {
                for (var fi = 0; fi < el.count; fi++) {
                    // read list count (assume uint8)
                    var cnt = dv.getUint8(offset); offset += 1;
                    if (cnt >= 3) {
                        var a = dv.getUint32(offset, little); offset += 4;
                        var b = dv.getUint32(offset, little); offset += 4;
                        var c = dv.getUint32(offset, little); offset += 4;
                        // skip remaining indices if any
                        if (cnt > 3) offset += 4 * (cnt - 3);
                        faces.push([a, b, c]);
                    } else {
                        // skip
                        offset += cnt * 4;
                    }
                }
            } else {
                // unknown element: attempt to skip fixed-size entries
                // assume float32 per property
                for (var k = 0; k < el.count; k++) {
                    offset += 4 * el.properties.length;
                }
            }
        }

        if (!vertices.length) return null;
        var flat = [];
        for (var f = 0; f < faces.length; f++) {
            var face = faces[f];
            for (var m = 0; m < face.length; m++) {
                var vv = vertices[face[m]];
                if (vv) flat.push(vv[0], vv[1], vv[2]);
            }
        }
        if (!flat.length) {
            for (var ii = 0; ii < vertices.length; ii++) {
                var vvv = vertices[ii];
                flat.push(vvv[0], vvv[1], vvv[2]);
            }
        }
        try { if (typeof console !== 'undefined') console.log('PLY parseBinary sample positions', flat.slice(0,12)); } catch(e){}
        var geometry = new root.THREE.BufferGeometry();
        geometry.setAttribute('position', new root.THREE.Float32BufferAttribute(flat, 3));
        geometry.computeVertexNormals();
        return geometry;
    }

    function PLYLoader() {}
    PLYLoader.prototype.load = function (url, onLoad, onProgress, onError) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.responseType = 'arraybuffer';
        xhr.onload = function () {
            if (xhr.status === 200 || xhr.status === 0) {
                try {
                    var buf = xhr.response;
                    var headerSlice = new TextDecoder('utf-8').decode(new Uint8Array(buf.slice(0, 1024)));
                    var header = parseHeader(headerSlice);
                    var geom = null;
                    if (header.format && header.format.indexOf('binary') === 0) {
                        geom = parseBinary(buf);
                    } else {
                        var text = new TextDecoder('utf-8').decode(new Uint8Array(buf));
                        geom = parseAscii(text);
                    }
                    onLoad && onLoad(geom);
                } catch (ex) {
                    onError && onError(ex);
                }
            } else {
                onError && onError(new Error('Unable to load PLY: ' + xhr.status));
            }
        };
        xhr.onerror = function () {
            onError && onError(new Error('Unable to load PLY'));
        };
        xhr.send(null);
    };

    root.THREE = root.THREE || {};
    root.THREE.PLYLoader = PLYLoader;
})(this);
