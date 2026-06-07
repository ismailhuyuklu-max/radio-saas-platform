namespace AdCastPro.SyncClient.Core.Models;

/// <summary>Login isteği body — POST /api/v1/sync/login.</summary>
public sealed record LoginRequest(
    string Username,
    string Password,
    string ClientVersion,
    string MachineId
);

/// <summary>Login + refresh dönüşü.</summary>
public sealed record AuthTokens(
    string AccessToken,
    string RefreshToken,
    int ExpiresIn,
    DateTimeOffset IssuedAt
);

/// <summary>Login response — user + radio + tokens.</summary>
public sealed record LoginResponse(
    AuthTokens Tokens,
    UserInfo User,
    RadioInfo? Radio,
    string MinClientVersion,
    bool NeedsUpdate
);

public sealed record UserInfo(string Id, string Username, string Role);

public sealed record RadioInfo(
    string Id,
    string Name,
    string? Frequency,
    string? Region,
    string? Province,
    bool NationalAccess
);

public sealed record RefreshRequest(string RefreshToken);

public sealed record MeResponse(UserInfo User, RadioInfo? Radio, Permissions Permissions, string MinClientVersion);

public sealed record Permissions(bool News, bool Ads, bool MediaPlan, bool Sponsor);
