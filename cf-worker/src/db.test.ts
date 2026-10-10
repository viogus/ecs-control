import { describe, it, expect } from 'vitest';
import { isHeartbeatDue } from './db';

/** UTC 时间戳(hh:mm:ss)，固定日期避免时区/夏令时干扰 */
function utc(h: number, m: number, s = 0): number {
  return Date.UTC(2026, 0, 15, h, m, s);
}

describe('isHeartbeatDue(D1 写入节流)', () => {
  it('默认 600s → 每 10 分钟的整点落一条', () => {
    expect(isHeartbeatDue(600, utc(12, 0))).toBe(true);
    expect(isHeartbeatDue(600, utc(12, 10))).toBe(true);
    expect(isHeartbeatDue(600, utc(12, 50))).toBe(true);
    expect(isHeartbeatDue(600, utc(12, 1))).toBe(false);
    expect(isHeartbeatDue(600, utc(12, 9))).toBe(false);
    expect(isHeartbeatDue(600, utc(12, 11))).toBe(false);
    expect(isHeartbeatDue(600, utc(12, 59))).toBe(false);
  });

  it('1800s → 每 30 分钟一条', () => {
    expect(isHeartbeatDue(1800, utc(12, 0))).toBe(true);
    expect(isHeartbeatDue(1800, utc(12, 30))).toBe(true);
    expect(isHeartbeatDue(1800, utc(12, 10))).toBe(false);
  });

  it('秒数不参与判定,只看整分钟', () => {
    expect(isHeartbeatDue(600, utc(12, 10, 0))).toBe(true);
    expect(isHeartbeatDue(600, utc(12, 10, 59))).toBe(true);
    expect(isHeartbeatDue(600, utc(12, 11, 59))).toBe(false);
  });

  it('<= 60s → 保持旧的每分钟行为', () => {
    expect(isHeartbeatDue(60, utc(12, 7))).toBe(true);
    expect(isHeartbeatDue(1, utc(12, 7))).toBe(true);
    // 不足一分钟的周期按分钟粒度处理,仍等价于每分钟
    expect(isHeartbeatDue(90, utc(12, 7))).toBe(true);
  });

  it('<= 0 或非法值 → 关闭心跳(脏值由调用方回退默认值)', () => {
    expect(isHeartbeatDue(0, utc(12, 0))).toBe(false);
    expect(isHeartbeatDue(-600, utc(12, 0))).toBe(false);
    expect(isHeartbeatDue(Number.NaN, utc(12, 0))).toBe(false);
    expect(isHeartbeatDue(Number.POSITIVE_INFINITY, utc(12, 0))).toBe(false);
  });

  it('60 分钟内恰好在跨小时处也命中(0 分钟)', () => {
    expect(isHeartbeatDue(3600, utc(13, 0))).toBe(true);
    expect(isHeartbeatDue(3600, utc(13, 1))).toBe(false);
  });
});
