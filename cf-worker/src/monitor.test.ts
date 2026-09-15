import { describe, it, expect } from 'vitest';
import { parseApiError, isCredentialError, isPermissionError, isNetworkError } from './monitor';

// 回归：CDT 错误分类必须与 PHP Helpers 的语义一致 ——
// 网络抖动绝不能判成鉴权失效，否则会误暂停自动停机保护。

const CREDENTIAL_MSG =
  'Aliyun ListCdtInternetTraffic error [InvalidAccessKeyId.NotFound]: Specified access key is not found.';
const PERMISSION_MSG =
  'Aliyun ListCdtInternetTraffic error [Forbidden.NoPermission]: The user is not authorized to operate CDT.';
const NETWORK_MSG = 'Aliyun ListCdtInternetTraffic: request failed';

describe('parseApiError', () => {
  it('提取服务端错误码与正文', () => {
    const parsed = parseApiError(CREDENTIAL_MSG);
    expect(parsed.code).toBe('InvalidAccessKeyId.NotFound');
    expect(parsed.message).not.toContain('[');
    expect(parsed.message).toContain('Specified access key is not found');
  });

  it('无错误码时 code 为空（说明请求未到达服务端）', () => {
    const parsed = parseApiError(NETWORK_MSG);
    expect(parsed.code).toBe('');
    expect(parsed.message).toBe(NETWORK_MSG);
  });
});

describe('isCredentialError', () => {
  it('按错误码判定 AK 失效', () => {
    const { code, message } = parseApiError(CREDENTIAL_MSG);
    expect(isCredentialError(code, message)).toBe(true);
  });

  it('按消息兜底判定 AK 失效', () => {
    expect(isCredentialError('', 'Specified access key is not found.')).toBe(true);
  });

  it('网络错误不是凭证错误', () => {
    const { code, message } = parseApiError(NETWORK_MSG);
    expect(isCredentialError(code, message)).toBe(false);
  });

  it('权限错误不是凭证错误', () => {
    const { code, message } = parseApiError(PERMISSION_MSG);
    expect(isCredentialError(code, message)).toBe(false);
  });
});

describe('isPermissionError', () => {
  it('Forbidden.* 判为权限不足', () => {
    const { code, message } = parseApiError(PERMISSION_MSG);
    expect(isPermissionError(code, message)).toBe(true);
  });

  it('AK 失效不算权限不足', () => {
    const { code, message } = parseApiError(CREDENTIAL_MSG);
    expect(isPermissionError(code, message)).toBe(false);
  });
});

describe('isNetworkError', () => {
  it('无服务端错误码即网络/传输层问题', () => {
    const { code, message } = parseApiError(NETWORK_MSG);
    expect(isNetworkError(code, message)).toBe(true);
  });

  it('响应解析失败不算网络错误（应归 sync_error）', () => {
    expect(isNetworkError('', 'Aliyun ListCdtInternetTraffic: invalid JSON response')).toBe(false);
  });

  it('带服务端错误码时不算网络错误', () => {
    const { code, message } = parseApiError(PERMISSION_MSG);
    expect(isNetworkError(code, message)).toBe(false);
  });
});
