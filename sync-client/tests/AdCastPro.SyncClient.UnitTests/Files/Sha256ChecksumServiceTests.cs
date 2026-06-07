using System.Text;
using AdCastPro.SyncClient.Infrastructure.Files;
using FluentAssertions;
using Xunit;

namespace AdCastPro.SyncClient.UnitTests.Files;

public class Sha256ChecksumServiceTests
{
    private readonly Sha256ChecksumService _service = new();

    [Theory]
    [InlineData("", "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855")]
    [InlineData("abc", "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad")]
    [InlineData("AdCastPro yayıncılık testi", "")]  // length>0 check only
    public async Task ComputeSha256_BilinenVektorler_DogruHash(string input, string expectedHash)
    {
        using var stream = new MemoryStream(Encoding.UTF8.GetBytes(input));
        var hash = await _service.ComputeSha256Async(stream);
        hash.Should().HaveLength(64);
        if (!string.IsNullOrEmpty(expectedHash))
        {
            hash.Should().Be(expectedHash);
        }
    }

    [Fact]
    public async Task ComputeSha256_BuyukStream_RamPatlamadan()
    {
        // 10MB random data — stream chunk-by-chunk işlenmeli
        var data = new byte[10_485_760];
        Random.Shared.NextBytes(data);
        using var stream = new MemoryStream(data);

        var hash = await _service.ComputeSha256Async(stream);
        hash.Should().HaveLength(64);
        hash.Should().MatchRegex("^[a-f0-9]{64}$");
    }

    [Fact]
    public async Task ComputeFileSha256_DosyaIcerigi_DogruHash()
    {
        var tempFile = Path.GetTempFileName();
        try
        {
            await File.WriteAllTextAsync(tempFile, "abc");
            var hash = await _service.ComputeFileSha256Async(tempFile);
            hash.Should().Be("ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad");
        }
        finally
        {
            File.Delete(tempFile);
        }
    }
}
