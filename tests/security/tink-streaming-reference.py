"""Reproduce public test vectors / verify PHP ciphertext with Tink 1.13.0.
Optional isolated test dependency only; never a Studio runtime dependency.
Run: python tink-streaming-reference.py generate | verify <directory>
Uses a deliberately public deterministic TEST KEY. Never use it for application data.
"""
import base64
import io
import json
import pathlib
import sys
import tink
from tink import streaming_aead, cleartext_keyset_handle
from tink.proto import aes_gcm_hkdf_streaming_pb2, tink_pb2, common_pb2

streaming_aead.register()
key = aes_gcm_hkdf_streaming_pb2.AesGcmHkdfStreamingKey(
    version=0, key_value=bytes(range(32)),
    params=aes_gcm_hkdf_streaming_pb2.AesGcmHkdfStreamingParams(
        ciphertext_segment_size=4096, derived_key_size=32, hkdf_hash_type=common_pb2.SHA256))
keyset = tink_pb2.Keyset(primary_key_id=1, key=[tink_pb2.Keyset.Key(
    key_data=tink_pb2.KeyData(type_url='type.googleapis.com/google.crypto.tink.AesGcmHkdfStreamingKey',
                            value=key.SerializeToString(), key_material_type=tink_pb2.KeyData.SYMMETRIC),
    status=tink_pb2.ENABLED, key_id=1, output_prefix_type=tink_pb2.RAW)])
primitive = cleartext_keyset_handle.read(tink.BinaryKeysetReader(keyset.SerializeToString())).primitive(streaming_aead.StreamingAead)
AAD = b'public-fixture-workspace/version'

class Output(io.BytesIO):
    def close(self):
        pass

if sys.argv[1] == 'generate':
    vectors = []
    for size in [0, 1, 4040, 4041, 8120, 8121, 20000]:
        plain = bytes((i * 37) % 256 for i in range(size))
        output = Output()
        with primitive.new_encrypting_stream(output, AAD) as stream:
            stream.write(plain)
        vectors.append({'size': size, 'ciphertext': base64.b64encode(output.getvalue()).decode()})
    path = pathlib.Path(__file__).parents[2] / 'packages/media-core/tests/Fixtures/tink-aes256-gcm-hkdf.json'
    path.write_text(json.dumps({'reference': 'Google Tink Python 1.13.0', 'segmentBytes': 4096,
                               'publicTestKey': bytes(range(32)).hex(), 'associatedData': AAD.decode(), 'vectors': vectors}, indent=2) + '\n')
    print(f'Generated {len(vectors)} reference vectors')
elif sys.argv[1] == 'verify':
    cases = list(pathlib.Path(sys.argv[2]).glob('*.cipher'))
    if not cases:
        raise RuntimeError('No PHP ciphertext cases to verify')
    for path in cases:
        with path.open('rb') as raw, primitive.new_decrypting_stream(raw, AAD) as decrypted:
            assert decrypted.read() == path.with_suffix('.plain').read_bytes()
    print(f'Google Tink accepted {len(cases)} PHP ciphertext cases')
else:
    raise RuntimeError('Unknown reference command')
