using System.Security.Cryptography;
using AdCastPro.SyncClient.Core.Abstractions;

namespace AdCastPro.SyncClient.Infrastructure.Files;

/// <summary>
/// SHA-256 streaming — büyük (multi-GB) dosyalarda RAM'i çökertmez.
/// 64KB buffer; ~250MB/s tipik throughput.
/// </summary>
public sealed class Sha256ChecksumService : IChecksumService
{
    private const int BufferSize = 65_536;

    public async Task<string> ComputeSha256Async(Stream source, CancellationToken ct = default)
    {
        using var sha = SHA256.Create();
        var buffer = new byte[BufferSize];
        int read;
        while ((read = await source.ReadAsync(buffer.AsMemory(0, BufferSize), ct)) > 0)
        {
            sha.TransformBlock(buffer, 0, read, null, 0);
        }
        sha.TransformFinalBlock(Array.Empty<byte>(), 0, 0);
        return Convert.ToHexStringLower(sha.Hash ?? throw new InvalidOperationException("Hash null"));
    }

    public async Task<string> ComputeFileSha256Async(string filePath, CancellationToken ct = default)
    {
        await using var fs = new FileStream(filePath, FileMode.Open, FileAccess.Read, FileShare.Read, BufferSize, useAsync: true);
        return await ComputeSha256Async(fs, ct);
    }
}
