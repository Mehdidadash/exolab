(function (root) {
    function parseBinarySTL(data) {
        var view = new DataView(data);
        var positions = [];
        var offset = 80;
        var faceCount = view.getUint32(offset, true);
        offset += 4;

        for (var i = 0; i < faceCount; i += 1) {
            offset += 12;
            for (var j = 0; j < 3; j += 1) {
                positions.push(view.getFloat32(offset, true));
                offset += 4;
                positions.push(view.getFloat32(offset, true));
                offset += 4;
                positions.push(view.getFloat32(offset, true));
                offset += 4;
            }
            offset += 2;
        }

        return positions;
    }

    function parseAsciiSTL(text) {
        var positions = [];
        var currentFace = [];
        var lines = text.split(/\r?\n/);
        for (var i = 0; i < lines.length; i += 1) {
            var line = lines[i].trim();
            if (!line) continue;
            if (line.indexOf('vertex') === 0) {
                var parts = line.split(/\s+/).slice(1);
                if (parts.length >= 3) {
                    currentFace.push([parseFloat(parts[0]), parseFloat(parts[1]), parseFloat(parts[2])]);
                    if (currentFace.length === 3) {
                        for (var f = 0; f < 3; f += 1) {
                            positions.push(currentFace[f][0], currentFace[f][1], currentFace[f][2]);
                        }
                        currentFace = [];
                    }
                }
            }
        }
        return positions;
    }

    function STLLoader() {}
    STLLoader.prototype.load = function (url, onLoad, onProgress, onError) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.responseType = 'arraybuffer';
        xhr.onload = function () {
            if (xhr.status === 200 || xhr.status === 0) {
                var data = xhr.response;
                var positions = [];
                if (data && data.byteLength) {
                    var headerText = new TextDecoder('utf-8').decode(new Uint8Array(data.slice(0, 80))).toLowerCase();
                    var isBinary = headerText.indexOf('solid') !== 0;
                    positions = isBinary ? parseBinarySTL(data) : parseAsciiSTL(new TextDecoder('utf-8').decode(new Uint8Array(data)));
                }
                var geometry = new root.THREE.BufferGeometry();
                if (positions.length) {
                    geometry.setAttribute('position', new root.THREE.Float32BufferAttribute(positions, 3));
                    geometry.computeVertexNormals();
                }
                onLoad && onLoad(geometry);
            } else {
                onError && onError(new Error('Unable to load STL: ' + xhr.status));
            }
        };
        xhr.onerror = function () {
            onError && onError(new Error('Unable to load STL'));
        };
        xhr.send(null);
    };

    root.THREE = root.THREE || {};
    root.THREE.STLLoader = STLLoader;
})(this);
